import { useState } from 'react';
import { api } from './api';
import { OfficeFinanceTabs } from './OfficeFinance';
import { OfficeFilters, useOfficeQuery } from './OfficePages';
import { OfficeHeading, OfficeFeedback, OfficePagination, OfficeTable, OfficeRowActions, OfficeEditor, OfficeDate, useOfficeRecords, type OfficePage, type OfficeRow } from './OfficeTools';

export function OfficeFinanceCategories() {
  const { params, query, change, reset } = useOfficeQuery();
  const records = useOfficeRecords<OfficePage>('office/finance/categories?' + query);
  const [editing, setEditing] = useState<OfficeRow | null>(null), [deleting, setDeleting] = useState<OfficeRow | null>(null);
  const [success, setSuccess] = useState('');
  return <>
    <OfficeHeading title="Financial entry categories" text="Organize financial entries with reusable categories.">
      <button type="button" className="button primary" onClick={() => setEditing({ active: true })}>Add category</button>
    </OfficeHeading>
    <OfficeFinanceTabs/>
    {success && <div className="platform-success" role="status">{success}</div>}
    <OfficeFilters params={params} onApply={change} onReset={reset} statuses={[[ '', 'All categories'], ['active', 'Active'], ['archived', 'Archived']]}/>
    <OfficeFeedback {...records} onRetry={records.reload}/>
    {records.data && <section className="platform-card">
      <p className="office-note">Archive categories to stop using them for new entries. Categories used by any entry retain their history and cannot be deleted.</p>
      <OfficeTable headings={['Category', 'Status', 'Entries', 'Updated', '']} empty={!records.data.data.length}>
        {records.data.data.map(category => <tr key={category.id}>
          <td><strong>{category.name}</strong></td>
          <td><span className={'platform-badge ' + (category.active ? 'active' : 'inactive')}>{category.active ? 'Active' : 'Archived'}</span></td>
          <td>{category.entries_count}</td>
          <td><OfficeDate value={category.updated_at}/></td>
          <OfficeRowActions label={category.name}>
            <button type="button" onClick={() => setEditing({ ...category, active: !!category.active })}>Edit category</button>
            <button type="button" className="danger-text" disabled={Number(category.entries_count) > 0} title={Number(category.entries_count) > 0 ? 'Archive this category to preserve its entry history.' : undefined} onClick={() => setDeleting(category)}>Delete category</button>
          </OfficeRowActions>
        </tr>)}
      </OfficeTable>
      <OfficePagination data={records.data} onPage={page => change({ page })} onSize={per_page => change({ per_page, page: 1 })}/>
    </section>}
    {editing && <OfficeEditor title={editing.id ? 'Edit financial category' : 'Add financial category'} initial={editing}
      fields={[{ name: 'name', label: 'Category name', required: true, max: 100 }, { name: 'active', label: 'Available for new entries', type: 'checkbox' }]}
      onClose={() => setEditing(null)} onSave={async values => {
        await api('office/finance/categories', { action: 'save', name: values.name, active: values.active, ...(editing.id ? { id: editing.id, version: editing.version } : {}) });
        setEditing(null); setSuccess('Financial category saved.'); records.reload();
      }}><p className="office-note">Renaming a category changes its name for future entries. Existing entries keep the category name recorded at the time.</p></OfficeEditor>}
    {deleting && <OfficeEditor title="Delete financial category" initial={{}} fields={[]} submitLabel="Delete category" onClose={() => setDeleting(null)} onSave={async () => {
      await api('office/finance/categories', { action: 'delete', id: deleting.id, version: deleting.version });
      setDeleting(null); setSuccess('Financial category deleted.'); records.reload();
    }}><p>Delete <strong>{deleting.name}</strong>? This unused category will be permanently removed.</p></OfficeEditor>}
  </>;
}
