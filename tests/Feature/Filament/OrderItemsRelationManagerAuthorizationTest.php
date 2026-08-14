<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\OrderResource\RelationManagers\OrderItemsRelationManager;
use App\Models\OrderItem;
use Tests\TestCase;

/**
 * OrderItemsRelationManager registers no CreateAction/EditAction/DeleteAction
 * today (recordActions/headerActions/toolbarActions are all empty — line
 * items are read-only snapshots), so this is currently unreachable through
 * the UI. It has the same class of hole BaseResource fixes for Resources
 * though: RelationManager's default getDeleteAuthorizationResponse() (what a
 * future DeleteAction would call) doesn't consult canDelete() — it falls
 * through to Gate/policy resolution, which allows by default with no
 * OrderItemPolicy. This proves the three get*AuthorizationResponse()
 * overrides added alongside BaseResource actually delegate to canCreate()/
 * canEdit()/canDelete() (all hardcoded false), so a later "let staff edit a
 * line item" feature can't silently reopen this by just adding an action.
 */
class OrderItemsRelationManagerAuthorizationTest extends TestCase
{
    public function test_create_edit_and_delete_are_all_denied_by_the_wired_methods(): void
    {
        $relationManager = new OrderItemsRelationManager;
        $item = new OrderItem;

        $this->assertFalse($relationManager->canCreate());
        $this->assertFalse($relationManager->getCreateAuthorizationResponse()->allowed());

        $this->assertFalse($relationManager->canEdit($item));
        $this->assertFalse($relationManager->getEditAuthorizationResponse($item)->allowed());

        $this->assertFalse($relationManager->canDelete($item));
        $this->assertFalse($relationManager->getDeleteAuthorizationResponse($item)->allowed());
    }
}
