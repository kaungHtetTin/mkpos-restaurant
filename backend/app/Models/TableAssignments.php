<?php

namespace App\Models;

class TableAssignments
{
    public static function occupy(int $orderId, int $tableId): void
    {
        BusinessData::table('order_tables')->insert(['business_id' => BusinessData::id(), 'order_id' => $orderId, 'table_id' => $tableId]);
        self::syncNames($orderId);
    }

    public static function syncNames(int $orderId): void
    {
        $names = BusinessData::table('order_tables')->join('dining_tables', 'dining_tables.id', '=', 'order_tables.table_id')->where('order_id', $orderId)->orderBy('dining_tables.id')->pluck('dining_tables.name')->all();
        BusinessData::table('orders')->where('id', $orderId)->update(['table_name' => implode(' + ', $names), 'table_names_snapshot' => json_encode($names)]);
    }

    public static function release(int $orderId): void
    {
        BusinessData::table('order_tables')->where('order_id', $orderId)->delete();
    }

    public static function transfer(object $order, int $tableId): array
    {
        abort_unless($order->type === 'dine_in', 422, 'Only dine-in orders can move tables.');
        $table = BusinessData::table('dining_tables')->where('id', $tableId)->where('active', true)->first();
        abort_unless($table, 422, 'Choose an active table in this business.');
        abort_if(BusinessData::table('order_tables')->where('table_id', $tableId)->exists(), 409, 'This table is already occupied. Refresh and choose an available table.');
        $previous = json_decode($order->table_names_snapshot ?? '[]', true);
        self::release($order->id);
        self::occupy($order->id, $tableId);
        BusinessData::table('orders')->where('id', $order->id)->update(['active_table_id' => $tableId, 'bill_requested' => false]);
        if (! $order->billing_group_ids) {
            BusinessData::table('orders')->where('id', $order->id)->update(['billing_label' => $table->name]);
        }
        BusinessData::table('kitchen_tickets')->where('order_id', $order->id)->update(['table_name' => $table->name]);

        return ['from_tables' => $previous, 'to_table' => $table->name];
    }

    public static function merge(object $source, object $target): array
    {
        abort_unless($source->id !== $target->id && $source->type === 'dine_in' && $target->type === 'dine_in', 422, 'Choose another dine-in order.');
        abort_unless($target->status === 'open' && ! $target->paid, 422, 'Only open, unpaid orders can be combined.');
        abort_if(BusinessData::table('payments')->whereIn('order_id', [$source->id, $target->id])->exists(), 422, 'Bills with recorded payments cannot be combined. Settle the remaining balances separately.');
        $groups = array_values(array_unique([...(json_decode($target->billing_group_ids ?? 'null', true) ?? [$target->id]), ...(json_decode($source->billing_group_ids ?? 'null', true) ?? [$source->id])]));
        BusinessData::table('order_items')->where('order_id', $source->id)->update(['order_id' => $target->id]);
        BusinessData::table('kitchen_tickets')->where('order_id', $source->id)->update(['order_id' => $target->id]);
        BusinessData::table('order_tables')->where('order_id', $source->id)->update(['order_id' => $target->id]);
        BusinessData::table('orders')->where('merged_into_id', $source->id)->update(['merged_into_id' => $target->id]);
        BusinessData::table('orders')->where('id', $source->id)->update(['status' => 'merged', 'merged_into_id' => $target->id, 'active_table_id' => null, 'closed_at' => now(), 'bill_requested' => false]);
        BusinessData::table('orders')->where('id', $target->id)->increment('version', 1, ['billing_group_ids' => json_encode($groups), 'bill_requested' => false, 'updated_at' => now()]);
        self::syncNames($target->id);

        return ['source_order_id' => $source->id, 'source_number' => $source->number, 'source_tables' => json_decode($source->table_names_snapshot ?? '[]', true), 'target_order_id' => $target->id, 'target_number' => $target->number];
    }
}
