<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Catalog;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Catalog\GetCatalogHealthReport;

class GetCatalogHealthReportTest extends TestCase
{
    /**
     * The report reveals back-office data quality — the tool must require the Products ACL.
     */
    public function testRequiresProductsAclResource(): void
    {
        $tool = new GetCatalogHealthReport($this->createMock(ProductCollectionFactory::class));

        $this->assertSame('Magento_Catalog::products', $tool->getRequiredAclResource());
        $this->assertSame('catalog_health_report', $tool->getName());
    }

    /**
     * Malformed arguments must fail validation before any collection is built.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->expects($this->never())->method('create');

        $tool = new GetCatalogHealthReport($factory);

        foreach ([['include_disabled' => 'yes'], ['sample_size' => 0]] as $arguments) {
            try {
                $tool->execute($arguments);
                $this->fail('Expected InvalidArgumentException for: ' . json_encode($arguments));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Each check counts its offenders and samples their SKUs; a healthy product appears
     * in no check.
     */
    public function testCountsProblemsWithSampleSkus(): void
    {
        $products = [
            new DataObject([
                'sku' => 'GOOD',
                'image' => '/g/o/good.jpg',
                'description' => 'Long text',
                'short_description' => '',
                'meta_description' => 'Meta',
                'price' => '10.00',
            ]),
            new DataObject([
                'sku' => 'BARE',
                'image' => 'no_selection',
                'description' => null,
                'short_description' => ' ',
                'meta_description' => null,
                'price' => '0',
            ]),
            new DataObject([
                'sku' => 'NOMETA',
                'image' => '/n/m.jpg',
                'description' => 'Text',
                'short_description' => null,
                'meta_description' => '',
                'price' => '5.00',
            ]),
        ];

        $result = (new GetCatalogHealthReport($this->factoryMock($products)))->execute([]);

        $this->assertSame(3, $result['products_scanned']);
        $this->assertSame(1, $result['checks']['missing_image']['count']);
        $this->assertSame(['BARE'], $result['checks']['missing_image']['sample_skus']);
        $this->assertSame(1, $result['checks']['missing_description']['count']);
        $this->assertSame(2, $result['checks']['missing_meta_description']['count']);
        $this->assertSame(['BARE', 'NOMETA'], $result['checks']['missing_meta_description']['sample_skus']);
        $this->assertSame(1, $result['checks']['zero_price']['count']);
        $this->assertFalse($result['include_disabled']);
    }

    /**
     * sample_size caps the SKU lists while the counts keep counting.
     */
    public function testSampleSizeCapsTheSkuList(): void
    {
        $products = [];
        for ($i = 1; $i <= 3; $i++) {
            $products[] = new DataObject([
                'sku' => 'P' . $i,
                'image' => '',
                'description' => 'x',
                'short_description' => 'x',
                'meta_description' => 'x',
                'price' => '1.00',
            ]);
        }

        $result = (new GetCatalogHealthReport($this->factoryMock($products)))
            ->execute(['sample_size' => 2]);

        $this->assertSame(3, $result['checks']['missing_image']['count']);
        $this->assertSame(['P1', 'P2'], $result['checks']['missing_image']['sample_skus']);
    }

    /**
     * Builds a product collection factory whose collection iterates the given rows.
     *
     * @param DataObject[] $products
     */
    private function factoryMock(array $products): ProductCollectionFactory
    {
        $collection = $this->createMock(ProductCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($products));

        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }
}
