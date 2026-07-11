<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Category;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Category\UpdateCategory;
use Yu\McpServer\Model\WriteToolInterface;

class UpdateCategoryTest extends TestCase
{
    /**
     * Editing catalog structure is a write operation gated by the Categories ACL.
     */
    public function testIsWriteToolWithCategoriesAcl(): void
    {
        $tool = new UpdateCategory($this->createMock(CategoryRepositoryInterface::class));

        $this->assertInstanceOf(WriteToolInterface::class, $tool);
        $this->assertSame('Magento_Catalog::categories', $tool->getRequiredAclResource());
        $this->assertSame('category_update', $tool->getName());
    }

    /**
     * category_id is mandatory, at least one editable field is required, and field types
     * are validated before the category is even loaded.
     */
    public function testThrowsOnInvalidArguments(): void
    {
        $repository = $this->createMock(CategoryRepositoryInterface::class);
        $repository->expects($this->never())->method('get');

        $tool = new UpdateCategory($repository);

        $invalid = [
            [],
            ['category_id' => 0],
            ['category_id' => 5],
            ['category_id' => 5, 'name' => ' '],
            ['category_id' => 5, 'is_active' => 'yes'],
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
     * An unknown category id is a business-logic error, not a crash.
     */
    public function testThrowsWhenCategoryDoesNotExist(): void
    {
        $repository = $this->createMock(CategoryRepositoryInterface::class);
        $repository->method('get')->willThrowException(new NoSuchEntityException());

        $tool = new UpdateCategory($repository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');
        $tool->execute(['category_id' => 999, 'name' => 'New name']);
    }

    /**
     * Root categories (level < 2) are structural and must be rejected without saving.
     */
    public function testRejectsRootCategories(): void
    {
        $category = $this->createMock(Category::class);
        $category->method('getLevel')->willReturn(1);

        $repository = $this->createMock(CategoryRepositoryInterface::class);
        $repository->method('get')->willReturn($category);
        $repository->expects($this->never())->method('save');

        $tool = new UpdateCategory($repository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('root category');
        $tool->execute(['category_id' => 2, 'name' => 'New name']);
    }

    /**
     * Only the provided fields change, the category loads in the global scope (store 0)
     * and every change is reported with its old and new value.
     */
    public function testUpdatesFieldsAndReportsChanges(): void
    {
        $category = $this->createMock(Category::class);
        $category->method('getLevel')->willReturn(2);
        $category->method('getName')->willReturn('Old name');
        $category->method('getIsActive')->willReturn(true);
        $category->expects($this->once())->method('setName')->with('New name');
        $category->expects($this->once())->method('setIsActive')->with(false);

        $repository = $this->createMock(CategoryRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('get')
            ->with(5, 0)
            ->willReturn($category);
        $repository->expects($this->once())->method('save')->with($category);

        $tool = new UpdateCategory($repository);

        $result = $tool->execute(['category_id' => 5, 'name' => 'New name', 'is_active' => false]);

        $this->assertSame(5, $result['category_id']);
        $this->assertSame(['from' => 'Old name', 'to' => 'New name'], $result['changes']['name']);
        $this->assertSame(['from' => true, 'to' => false], $result['changes']['is_active']);
    }
}
