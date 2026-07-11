<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Product;

use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Product\DuplicateProduct;
use Yu\McpServer\Model\WriteToolInterface;

class DuplicateProductTest extends TestCase
{
    /**
     * Duplicating a product is a write operation gated by the Products ACL.
     */
    public function testIsWriteToolWithProductsAcl(): void
    {
        $tool = $this->tool($this->createMock(ProductRepositoryInterface::class));

        $this->assertInstanceOf(WriteToolInterface::class, $tool);
        $this->assertSame('Magento_Catalog::products', $tool->getRequiredAclResource());
        $this->assertSame('product_duplicate', $tool->getName());
    }

    /**
     * Both SKUs are mandatory, must differ and optional overrides are type-checked
     * before anything is loaded.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects($this->never())->method('get');

        $tool = $this->tool($repository);

        $invalid = [
            [],
            ['source_sku' => 'A'],
            ['source_sku' => 'A', 'new_sku' => 'A'],
            ['source_sku' => 'A', 'new_sku' => 'B', 'price' => 0],
            ['source_sku' => 'A', 'new_sku' => 'B', 'name' => ' '],
        ];
        foreach ($invalid as $arguments) {
            try {
                $tool->execute($arguments);
                $this->fail('Expected InvalidArgumentException for: ' . json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Only simple products can be duplicated in v1 (same scope as product_create).
     */
    public function testRejectsNonSimpleSource(): void
    {
        $source = $this->createMock(Product::class);
        $source->method('getTypeId')->willReturn('configurable');

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturn($source);

        $tool = $this->tool($repository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('only simple products');
        $tool->execute(['source_sku' => 'CONF', 'new_sku' => 'CONF-2']);
    }

    /**
     * An occupied target SKU is a hard error — duplication never becomes an update.
     */
    public function testRejectsExistingTargetSku(): void
    {
        $source = $this->createMock(Product::class);
        $source->method('getTypeId')->willReturn('simple');
        $existing = $this->createMock(Product::class);
        $existing->method('getId')->willReturn(42);

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(
            static fn (string $sku) => $sku === 'SRC' ? $source : $existing
        );

        $tool = $this->tool($repository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        $tool->execute(['source_sku' => 'SRC', 'new_sku' => 'TAKEN']);
    }

    /**
     * The copy takes the source's commercial fields, is forced disabled with zero stock,
     * and inherits the source's category assignments.
     */
    public function testDuplicatesDisabledWithSourceFields(): void
    {
        $source = $this->createMock(Product::class);
        $source->method('getTypeId')->willReturn('simple');
        $source->method('getName')->willReturn('Original');
        $source->method('getPrice')->willReturn(99.0);
        $source->method('getAttributeSetId')->willReturn(4);
        $source->method('getVisibility')->willReturn(4);
        $source->method('getWebsiteIds')->willReturn([1]);
        $source->method('getCategoryIds')->willReturn([3, 5]);
        $source->method('getData')->willReturnMap([
            ['description', null, 'Long text'],
            ['short_description', null, null],
            ['meta_title', null, ''],
            ['meta_keyword', null, null],
            ['meta_description', null, 'Meta'],
            ['tax_class_id', null, '2'],
            ['weight', null, '1.5'],
        ]);

        $saved = $this->createMock(Product::class);
        $saved->method('getId')->willReturn(77);
        $saved->method('getSku')->willReturn('SRC-2');
        $saved->method('getName')->willReturn('Original (copy)');
        $saved->method('getPrice')->willReturn(99.0);

        $new = $this->createMock(Product::class);
        $new->expects($this->once())->method('setSku')->with('SRC-2');
        $new->expects($this->once())->method('setName')->with('Original (copy)');
        $new->expects($this->once())->method('setStatus')->with(Status::STATUS_DISABLED);
        $new->expects($this->once())->method('setPrice')->with(99.0);
        $new->expects($this->once())->method('setStockData')->with(['qty' => 0, 'is_in_stock' => 0]);
        $setData = [];
        $new->method('setData')->willReturnCallback(
            static function (string $key, $value) use (&$setData, $new) {
                $setData[$key] = $value;
                return $new;
            }
        );

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(
            static function (string $sku) use ($source) {
                if ($sku === 'SRC') {
                    return $source;
                }
                throw new NoSuchEntityException();
            }
        );
        $repository->expects($this->once())->method('save')->with($new)->willReturn($saved);

        $factory = $this->createMock(ProductFactory::class);
        $factory->method('create')->willReturn($new);

        $categoryLink = $this->createMock(CategoryLinkManagementInterface::class);
        $categoryLink->expects($this->once())
            ->method('assignProductToCategories')
            ->with('SRC-2', [3, 5]);

        $tool = new DuplicateProduct($repository, $factory, $categoryLink);

        $result = $tool->execute(['source_sku' => 'SRC', 'new_sku' => 'SRC-2']);

        $this->assertSame(77, $result['product']['id']);
        $this->assertSame('disabled', $result['product']['status']);
        $this->assertSame('SRC', $result['product']['source_sku']);
        $this->assertSame([3, 5], $result['product']['category_ids']);
        // Empty/null source attributes are skipped, filled ones are copied.
        $this->assertSame('Long text', $setData['description']);
        $this->assertSame('Meta', $setData['meta_description']);
        $this->assertArrayNotHasKey('short_description', $setData);
        $this->assertArrayNotHasKey('meta_title', $setData);
    }

    /**
     * Builds the tool with default collaborator mocks.
     */
    private function tool(ProductRepositoryInterface $repository): DuplicateProduct
    {
        return new DuplicateProduct(
            $repository,
            $this->createMock(ProductFactory::class),
            $this->createMock(CategoryLinkManagementInterface::class)
        );
    }
}
