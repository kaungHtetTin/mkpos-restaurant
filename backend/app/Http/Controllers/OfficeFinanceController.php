<?php

namespace App\Http\Controllers;

use App\Models\OfficeData;
use App\Models\PlatformLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OfficeFinanceController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $size = OfficeData::pageSize($r);
        $r->validate(['currency' => 'nullable|regex:/^[A-Z]{3}$/', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'.($r->filled('from') ? '|after_or_equal:from' : ''), 'q' => 'nullable|string|max:100', 'kind' => 'nullable|in:subscription,income,expense,opening,transfer,contribution,allocation', 'book_id' => 'nullable|integer', 'category_id' => 'nullable|integer|min:1', 'year' => 'nullable|integer|between:2000,2100']);
        $currency = $r->input('currency', 'MMK');
        $books = DB::table('office_bank_books')->orderBy('id')->get();
        $books->each(fn ($book) => $book->balance = PlatformLedger::balance($book->id));
        $query = DB::table('office_ledger')->leftJoin('office_bank_books as books', 'books.id', '=', 'office_ledger.book_id')->leftJoin('office_bank_books as destinations', 'destinations.id', '=', 'office_ledger.to_book_id')->leftJoin('users', 'users.id', '=', 'office_ledger.created_by')->leftJoin('businesses', 'businesses.id', '=', 'office_ledger.business_id')->where('office_ledger.currency', $currency);
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if ($r->filled($field)) {
                $query->where('entry_date', $operator, $r->$field);
            }
        }
        if ($r->filled('kind')) {
            $query->where('kind', $r->kind);
        }
        if ($r->filled('book_id')) {
            $query->where(fn ($q) => $q->where('book_id', $r->book_id)->orWhere('to_book_id', $r->book_id));
        }
        if ($r->filled('category_id')) {
            $query->where('office_ledger.category_id', $r->category_id);
        }
        if ($r->filled('q')) {
            $query->where(fn ($q) => $q->where('description', 'like', '%'.$r->q.'%')->orWhere('reference', 'like', '%'.$r->q.'%')->orWhere('businesses.name', 'like', '%'.$r->q.'%'));
        }
        $year = (int) $r->input('year', now(DB::table('office_settings')->value('timezone'))->year);
        $trendEntries = DB::table('office_ledger')->where('currency', $currency)->whereNull('voided_at')->whereIn('kind', ['subscription', 'income', 'expense'])->where('entry_date', '>=', ($year - 4).'-01-01')->where('entry_date', '<', ($year + 1).'-01-01')->get();
        $sum = fn ($rows) => ['income' => (int) $rows->whereIn('kind', ['subscription', 'income'])->sum('amount'), 'expenses' => (int) $rows->where('kind', 'expense')->sum('amount')];
        $monthly = collect(range(1, 12))->map(fn ($month) => ['month' => $month, ...$sum($trendEntries->filter(fn ($entry) => substr($entry->entry_date, 0, 7) === sprintf('%04d-%02d', $year, $month)))])->all();
        $yearly = collect(range($year - 4, $year))->map(fn ($value) => ['year' => $value, ...$sum($trendEntries->filter(fn ($entry) => (int) substr($entry->entry_date, 0, 4) === $value))])->all();
        $period = (clone $query)->whereNull('voided_at')->get(['kind', 'amount']);

        return response()->json(['entries' => $query->select('office_ledger.*', 'books.name as book_name', 'destinations.name as destination_name', 'users.name as actor_name', 'businesses.name as business_name')->orderByDesc('office_ledger.id')->paginate($size), 'books' => $books, 'categories' => DB::table('office_finance_categories')->orderBy('name')->get(['id', 'name', 'active', 'version']), 'founders' => DB::table('office_founders')->orderBy('id')->get(), 'settings_version' => (int) DB::table('office_settings')->where('id', 1)->value('version'), 'currency' => $currency, 'trends' => ['year' => $year, 'monthly' => $monthly, 'yearly' => $yearly], 'profit' => PlatformLedger::profit($currency), 'period' => ['income' => (int) $period->whereIn('kind', ['subscription', 'income'])->sum('amount'), 'expenses' => (int) $period->where('kind', 'expense')->sum('amount')]]);
    }

    public function save(Request $r): JsonResponse
    {
        $data = $r->validate(['action' => 'required|in:book,entry,void,founders', 'id' => 'nullable|integer', 'version' => 'nullable|integer|min:1', 'name' => 'required_if:action,book|string|max:120', 'currency' => 'required_if:action,book,entry|regex:/^[A-Z]{3}$/', 'active' => 'required_if:action,book|boolean', 'is_default' => 'required_if:action,book|boolean', 'kind' => 'required_if:action,entry|in:income,expense,opening,transfer,contribution,allocation', 'book_id' => 'required_if:action,entry|integer', 'to_book_id' => 'required_if:kind,transfer|nullable|integer|different:book_id', 'amount' => 'required_if:action,entry|integer|between:-1000000000,1000000000', 'description' => 'required_if:action,entry|string|max:255', 'reference' => 'nullable|string|max:150', 'category' => 'nullable|string|max:100', 'category_id' => 'nullable|integer|min:1', 'entry_date' => 'required_if:action,entry|date_format:Y-m-d|before_or_equal:'.now(DB::table('office_settings')->value('timezone'))->toDateString(), 'reason' => 'required_if:action,void|nullable|string|max:255', 'founders' => 'required_if:action,founders|array|max:30', 'founders.*.id' => 'nullable|integer|distinct', 'founders.*.name' => 'required|string|max:100', 'founders.*.share_bps' => 'required|integer|between:0,10000', 'settings_version' => 'required_if:action,founders|required_if:kind,allocation|integer|min:1']);
        if ($data['action'] === 'founders') {
            OfficeData::super();
        }

        return OfficeData::write($r, function () use ($data) {
            $action = $data['action'];
            if ($action === 'book') {
                $id = $data['id'] ?? null;
                $old = $id ? DB::table('office_bank_books')->find($id) : null;
                if ($id) {
                    abort_unless($old, 404);
                    abort_unless($old->version === (int) ($data['version'] ?? 0), 409, 'This bank book changed. Reopen it before saving.');
                    abort_if($old->currency !== $data['currency'], 422, 'A bank book currency cannot change. Create another book.');
                    abort_if($old->is_default && ! $data['active'], 422, 'Choose another default receiving book before archiving this book.');
                    abort_if($old->is_default && ! $data['is_default'], 422, 'Set another book as default to replace this receiving book.');
                }
                abort_if($data['is_default'] && ! $data['active'], 422, 'The default receiving book must be active.');
                if ($data['is_default']) {
                    DB::table('office_bank_books')->where('currency', $data['currency'])->update(['is_default' => false, 'version' => DB::raw('version + 1')]);
                }
                $values = ['name' => $data['name'], 'currency' => $data['currency'], 'active' => $data['active'], 'is_default' => $data['is_default']];
                if ($id) {
                    DB::table('office_bank_books')->where('id', $id)->update([...$values, 'version' => $old->version + 1]);
                } else {
                    $id = DB::table('office_bank_books')->insertGetId($values);
                }
                OfficeData::audit(null, 'bank_book_saved', ['id' => $id, ...$values]);
            } elseif ($action === 'founders') {
                $settings = DB::table('office_settings')->find(1);
                abort_unless($settings->version === (int) $data['settings_version'], 409, 'Platform settings changed. Refresh before saving founders.');
                abort_unless(collect($data['founders'])->sum('share_bps') === 10000, 422, 'Active founder shares must total 100%.');
                $kept = [];
                foreach ($data['founders'] as $row) {
                    $values = ['name' => $row['name'], 'share_bps' => $row['share_bps'], 'active' => true];
                    if (! empty($row['id'])) {
                        abort_unless(DB::table('office_founders')->where('id', $row['id'])->exists(), 422, 'Unknown founder.');
                        DB::table('office_founders')->where('id', $row['id'])->update($values);
                        $kept[] = $row['id'];
                    } else {
                        $kept[] = DB::table('office_founders')->insertGetId($values);
                    }
                }
                DB::table('office_founders')->whereNotIn('id', $kept)->update(['active' => false]);
                DB::table('office_settings')->where('id', 1)->increment('version');
                OfficeData::audit(null, 'founders_saved', ['founders' => $data['founders']]);
            } elseif ($action === 'void') {
                $entry = DB::table('office_ledger')->find($data['id'] ?? 0);
                abort_unless($entry, 404);
                abort_if($entry->voided_at || $entry->subscription_payment_id, 422, 'This entry cannot be voided. Subscription receipts are immutable.');
                $out = in_array($entry->kind, ['expense', 'allocation']);
                abort_if(! $out && $entry->kind !== 'transfer' && PlatformLedger::balance($entry->book_id) - $entry->amount < 0, 422, 'Voiding this entry would overdraw its bank book.');
                if ($entry->kind === 'transfer') {
                    abort_if(PlatformLedger::balance($entry->to_book_id) < $entry->amount, 422, 'The receiving book no longer has the transferred funds.');
                }
                DB::table('office_ledger')->where('id', $entry->id)->update(['voided_at' => now(), 'voided_by' => Auth::guard('office')->id(), 'void_reason' => $data['reason']]);
                OfficeData::audit(null, 'financial_entry_voided', ['id' => $entry->id, 'reason' => $data['reason']]);
            } else {
                $category = null;
                if (! empty($data['category_id'])) {
                    $category = DB::table('office_finance_categories')->where('active', true)->find($data['category_id']);
                    abort_unless($category, 422, 'Choose an active financial category.');
                }
                abort_if(! $category && ! empty($data['category']), 422, 'Choose a saved financial category.');
                $book = PlatformLedger::book($data['currency'], $data['book_id']);
                $amount = (int) $data['amount'];
                abort_if($amount === 0 || ($amount < 0 && $data['kind'] !== 'opening'), 422, 'Enter a positive amount, or a signed opening adjustment.');
                $out = in_array($data['kind'], ['expense', 'transfer', 'allocation']);
                abort_if(PlatformLedger::balance($book->id) + ($out ? -$amount : $amount) < 0, 422, 'This bank book has insufficient funds.');
                $snapshot = null;
                $target = null;
                if ($data['kind'] === 'transfer') {
                    $target = PlatformLedger::book($data['currency'], $data['to_book_id'] ?? 0);
                    abort_if($target->id === $book->id, 422, 'Choose a different receiving book.');
                }
                if ($data['kind'] === 'allocation') {
                    abort_unless((int) DB::table('office_settings')->where('id', 1)->value('version') === (int) $data['settings_version'], 409, 'Founder shares or platform settings changed. Close the form, refresh finance and review the allocation again.');
                    abort_if($amount > PlatformLedger::profit($book->currency)['available_profit'], 422, 'The allocation exceeds unallocated earned profit. Opening balances and contributions are not profit.');
                    $founders = DB::table('office_founders')->where('active', true)->orderBy('id')->get();
                    abort_unless((int) $founders->sum('share_bps') === 10000, 422, 'Configure founder shares totaling 100% before allocating profit.');
                    $snapshot = $founders->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'share_bps' => $f->share_bps, 'amount' => intdiv($amount * $f->share_bps, 10000)])->all();
                    $remainder = $amount - array_sum(array_column($snapshot, 'amount'));
                    $ranking = array_keys($snapshot);
                    usort($ranking, fn ($a, $b) => ($amount * $snapshot[$b]['share_bps'] % 10000) <=> ($amount * $snapshot[$a]['share_bps'] % 10000));
                    foreach (array_slice($ranking, 0, $remainder) as $index) {
                        $snapshot[$index]['amount']++;
                    }
                }
                $id = DB::table('office_ledger')->insertGetId(['book_id' => $book->id, 'to_book_id' => $target?->id, 'created_by' => Auth::guard('office')->id(), 'kind' => $data['kind'], 'amount' => $amount, 'currency' => $book->currency, 'description' => $data['description'], 'category' => $category?->name ?? '', 'category_id' => $category?->id, 'reference' => $data['reference'] ?? '', 'entry_date' => $data['entry_date'], 'allocation_snapshot' => $snapshot ? json_encode($snapshot) : null, 'created_at' => now()]);
                OfficeData::audit(null, 'financial_entry_recorded', ['id' => $id, 'kind' => $data['kind'], 'amount' => $amount, 'book_id' => $book->id]);
            }

            return ['saved' => true];
        });
    }
}
