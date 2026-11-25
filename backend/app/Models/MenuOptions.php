<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;

class MenuOptions
{
    /** @return array{price: int, options_snapshot: string} */
    public static function quote(object $menu, array $data, ?int $businessId = null): array
    {
        $options = DB::table('menu_options')->where('business_id', $businessId ?? BusinessData::id())->where('menu_item_id', $menu->id)->whereNull('archived_at')->get();
        if ($menu->uses_variants || $options->isNotEmpty() || isset($data['options_version'])) {
            abort_unless((int) ($data['options_version'] ?? 0) === $menu->options_version, 409, 'Sizes or add-ons changed. Close this dialog and review the latest choices.');
        }
        $price = (int) $menu->price;
        $snapshot = [];
        if ($menu->uses_variants) {
            $variant = $options->first(fn ($option) => $option->id === (int) ($data['variant_id'] ?? 0) && $option->kind === 'variant' && $option->active);
            abort_unless($variant, 422, 'Choose an available size for this item.');
            $price = (int) $variant->price;
            $snapshot[] = ['id' => $variant->id, 'kind' => 'variant', 'name' => $variant->name, 'price' => $price];
        } else {
            abort_if(! empty($data['variant_id']), 422, 'This menu item does not have sizes.');
        }
        foreach ($data['addon_ids'] ?? [] as $addonId) {
            $addon = $options->first(fn ($option) => $option->id === (int) $addonId && $option->kind === 'addon' && $option->active);
            abort_unless($addon, 422, 'A selected add-on is unavailable or belongs to another item.');
            $price += $addon->price;
            $snapshot[] = ['id' => $addon->id, 'kind' => 'addon', 'name' => $addon->name, 'price' => (int) $addon->price];
        }
        abort_if($price > 10000000, 422, 'The price with selected options exceeds the maximum unit price.');

        return ['price' => $price, 'options_snapshot' => json_encode($snapshot)];
    }

    public static function validateDraft(object $item): void
    {
        $snapshot = json_decode($item->options_snapshot ?? '[]', true);
        abort_if(BusinessData::table('menu_items')->where('id', $item->menu_item_id)->value('uses_variants') && ! collect($snapshot)->contains('kind', 'variant'), 422, $item->name.' now requires a size. Edit its choices before sending.');
        foreach ($snapshot as $option) {
            abort_unless(BusinessData::table('menu_options')->where('id', $option['id'])->where('menu_item_id', $item->menu_item_id)->where('active', true)->whereNull('archived_at')->exists(), 422, $item->name.' has an unavailable size or add-on. Edit its choices before sending.');
        }
    }

    public static function save(int $menuId, array $data): void
    {
        $menu = BusinessData::table('menu_items')->find($menuId);
        abort_unless($menu, 404);
        abort_unless($menu->options_version === (int) $data['options_version'], 409, 'Someone updated these options. Reopen the editor to review the latest choices.');
        $kept = [];
        foreach (['variants' => 'variant', 'addons' => 'addon'] as $group => $kind) {
            foreach ($data[$group] as $row) {
                $values = ['name' => $row['name'], 'price' => $row['price'], 'active' => $row['active']];
                if (! empty($row['id'])) {
                    abort_if(in_array((int) $row['id'], $kept, true), 422, 'An option can only be included once.');
                    $option = BusinessData::table('menu_options')->where('menu_item_id', $menuId)->where('kind', $kind)->whereNull('archived_at')->find($row['id']);
                    abort_unless($option, 422, 'An option does not belong to this menu item.');
                    BusinessData::table('menu_options')->where('id', $option->id)->update($values);
                    $kept[] = $option->id;
                } else {
                    $kept[] = DB::table('menu_options')->insertGetId(['business_id' => BusinessData::id(), 'menu_item_id' => $menuId, 'kind' => $kind, ...$values]);
                }
            }
        }
        BusinessData::table('menu_options')->where('menu_item_id', $menuId)->whereNotIn('id', $kept)->whereNull('archived_at')->update(['active' => false, 'archived_at' => now()]);
        BusinessData::table('menu_items')->where('id', $menuId)->increment('options_version', 1, ['uses_variants' => count($data['variants']) > 0]);
    }
}
