import { tr, useLocale } from './locale';
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';

import { useLocation, useSearchParams } from 'react-router-dom';

import { ArrowDownLeft, ArrowUpRight, Banknote, Pencil, Plus, Tags, Trash2 } from 'lucide-react';

import { api } from './api';

import { useApp, money } from './context';

import { Empty, PageHeading } from './components';

import { OfficeEditor, type OfficeRow } from './OfficeTools';

import { useLiveRefresh } from './realtime';

import './business-finance.css';



interface Category { id:number; name:string; active:boolean; version:number; entries_count:number }

interface Entry { id:number; kind:'income'|'expense'; amount:number; currency:string; category_id:number; category_name:string; entry_date:string; description:string; notes:string|null; version:number; voided_at:string|null; void_reason:string|null }

interface Finance { currency:string; categories:Category[]; totals:{income:number;expense:number;net:number}; entries:{data:Entry[];current_page:number;last_page:number;total:number} }

const cache=new Map<string,Finance>();

const scrolls=new Map<string,number>();

function clearBusinessFinanceCache(){cache.clear();scrolls.clear();}

window.addEventListener('realtime:finance',()=>cache.clear());

window.addEventListener('session-expired',clearBusinessFinanceCache);

window.addEventListener('finance-cache-clear',clearBusinessFinanceCache);



export function BusinessFinancePage(){
  useLocale();

 const {state,user,connected,notify}=useApp();

 const [params,setParams]=useSearchParams();const location=useLocation();

 const tab=params.get('tab')==='categories'?'categories':'entries';

 const query=new URLSearchParams(params);query.delete('tab');const queryString=query.toString();

 const key=`${state.business.id}:${user.id}:${location.key}:${queryString}`,sequence=useRef(0);

 const [data,setData]=useState<Finance|null>(()=>cache.get(key)||null),[error,setError]=useState('');

 const [entry,setEntry]=useState<OfficeRow|null>(null),[category,setCategory]=useState<OfficeRow|null>(null),[deleting,setDeleting]=useState<Category|null>(null),[voiding,setVoiding]=useState<Entry|null>(null);

 const writable=connected&&state.writable;

 const load=useCallback(async()=>{

  const request=++sequence.current;

  try{const result=await api<Finance>("business-finance?"+queryString);if(request!==sequence.current)return;cache.set(key,result);if(cache.size>40)cache.delete(cache.keys().next().value!);setData(result);setError('');}catch(e){if(request===sequence.current)setError((e as Error).message);}

 },[key,queryString]);

 useEffect(()=>{const existing=cache.get(key);if(existing){setData(existing);setError('');}else{void load();}return()=>{sequence.current++;};},[key,load]);

 useLiveRefresh(load,'finance');

 useLayoutEffect(()=>{const frame=requestAnimationFrame(()=>window.scrollTo(0,scrolls.get(key)||0));return()=>{cancelAnimationFrame(frame);scrolls.set(key,window.scrollY);};},[key]);

 const change=(values:Record<string,string|number>)=>{const next=new URLSearchParams(params);for(const [name,value]of Object.entries(values)){if(value==='')next.delete(name);else next.set(name,String(value));}setParams(next);};

 const saved=async(message:string)=>{cache.clear();await load();notify(message);};

 const today=new Date().toLocaleDateString('en-CA',{timeZone:state.settings.timezone});

 return <div className="business-finance"><PageHeading eyebrow={tr("YOUR BUSINESS FINANCES")} title={tr("Income & expenses")} subtitle={tr("Record money coming in and going out. POS sales are tracked separately.")}>

  {tab==='entries'?<button className="button primary" disabled={!writable||!data?.categories.some(c=>c.active)} onClick={()=>setEntry({kind:'income',entry_date:today,category_id:data?.categories.find(c=>c.active)?.id,amount:'',description:'',notes:''})}><Plus size={16}/>{tr("Add entry")}</button>:<button className="button primary" disabled={!writable} onClick={()=>setCategory({name:'',active:true})}><Plus size={16}/>{tr("Add category")}</button>}

 </PageHeading>

 <div className="finance-tabs tabs"><button className={tab==='entries'?'selected':''} onClick={()=>change({tab:'',page:''})}><Banknote size={16}/>{tr("Entries")}</button><button className={tab==='categories'?'selected':''} onClick={()=>change({tab:'categories'})}><Tags size={16}/>{tr("Categories")}</button></div>

 {error&&<div className="form-error" role="alert">{tr(error)}<button className="text-button" onClick={()=>void load()}>{tr("Retry")}</button></div>}

 {!data&&!error&&<p className="muted" role="status">{tr("Loading finances...")}</p>}

 {data&&tab==='entries'&&<>

 <form key={queryString} className="panel finance-filters" onSubmit={event=>{event.preventDefault();change({...Object.fromEntries(new FormData(event.currentTarget)) as Record<string,string>,page:''});}}>

  <label className="finance-search">{tr("Search")}<input name="q" defaultValue={params.get('q')||''} placeholder={tr("Description or category")} maxLength={100}/></label>

  <label>{tr("Type")}<select name="kind" defaultValue={params.get('kind')||''}><option value="">{tr("All types")}</option><option value="income">{tr("Income")}</option><option value="expense">{tr("Expense")}</option></select></label>

  <label>{tr("Category")}<select name="category_id" defaultValue={params.get('category_id')||''}><option value="">{tr("All categories")}</option>{data.categories.map(c=><option key={c.id} value={c.id}>{c.name}{!c.active?tr(" (archived)"):''}</option>)}</select></label>

  <label>{tr("From")}<input type="date" name="from" defaultValue={params.get('from')||''}/></label><label>{tr("To")}<input type="date" name="to" defaultValue={params.get('to')||''}/></label>

  <label>{tr("Status")}<select name="status" defaultValue={params.get('status')||'active'}><option value="active">{tr("Active entries")}</option><option value="voided">{tr("Voided entries")}</option><option value="all">{tr("All entries")}</option></select></label>

  <div className="finance-filter-actions"><button type="submit" className="button">{tr("Apply filters")}</button><button type="button" className="text-button" onClick={()=>setParams({})}>{tr("Clear")}</button></div>

 </form>

 <div className="stats-grid finance-totals">{[[tr("Income"),data.totals.income],[tr("Expenses"),data.totals.expense],[tr("Net balance"),data.totals.net]].map(([label,value])=><section className="stat" key={label}><span className="muted">{label}</span><strong>{money(Number(value),data.currency)}</strong></section>)}</div>

 <p className="finance-help muted">{tr("Totals match the search, type, category and dates above. Voided entries are excluded.")}</p>

 <section className="panel finance-records" aria-label={tr("Financial entries")}>

 {data.entries.data.length?data.entries.data.map(row=><article className={`finance-record ${row.voided_at?'finance-voided':''}`} key={row.id}>

  <span className={`finance-kind ${row.kind}`}>{row.kind==='income'?<ArrowDownLeft size={18}/>:<ArrowUpRight size={18}/>}<span>{row.kind==='income'?tr("Income"):tr("Expense")}</span></span>

  <div className="finance-record-copy"><strong>{row.description}</strong><p className="muted">{row.entry_date} · {row.category_name}</p>{row.notes&&<p>{row.notes}</p>}{row.voided_at&&<p className="danger-text">{tr("Voided:")} {row.void_reason}</p>}</div>

  <strong className="finance-amount">{row.kind==='income'?'+':'-'}{money(row.amount,row.currency)}</strong>

  <div className="finance-row-actions">{!row.voided_at?<><button className="icon-button" aria-label={tr("Edit {value1}", {value1: row.description})} disabled={!writable} onClick={()=>setEntry({...row})}><Pencil size={16}/></button><button className="icon-button danger-text" aria-label={tr("Void {value1}", {value1: row.description})} disabled={!writable} onClick={()=>setVoiding(row)}><Trash2 size={16}/></button></>:<span className="badge cancelled">{tr("Voided")}</span>}</div>

 </article>):<Empty title={tr("No financial entries")} text={!data.categories.some(c=>c.active)?tr("Add a category to start recording income and expenses."):tr("Try different filters or add your first entry.")}>{!data.categories.some(c=>c.active)&&<button className="button" onClick={()=>change({tab:'categories'})}>{tr("Manage categories")}</button>}</Empty>}

 <div className="pagination"><span>{data.entries.total}  {tr("entries")}</span><div><button className="button small" disabled={data.entries.current_page<=1} onClick={()=>change({page:data.entries.current_page-1})}>{tr("Previous")}</button><span>{data.entries.current_page} / {data.entries.last_page}</span><button className="button small" disabled={data.entries.current_page>=data.entries.last_page} onClick={()=>change({page:data.entries.current_page+1})}>{tr("Next")}</button></div></div>

 </section></>}

 {data&&tab==='categories'&&<section className="panel finance-records"><p className="finance-category-help muted">{tr("Archive categories used by entries. Recorded category names stay unchanged when you rename a category.")}</p>{data.categories.length?data.categories.map(row=><article className="finance-category" key={row.id}><div><strong>{row.name}</strong><p className="muted">{row.entries_count}  {tr("entries ·")} {row.active?tr("Active"):tr("Archived")}</p></div><div className="finance-row-actions"><button className="button small" disabled={!writable} onClick={()=>setCategory({...row,active:!!row.active})}>{tr("Edit category")}</button><button className="icon-button danger-text" aria-label={tr("Delete {value1}", {value1: row.name})} disabled={!writable||row.entries_count>0} title={row.entries_count>0?tr("Archive used categories to preserve history."):tr("Delete unused category")} onClick={()=>setDeleting(row)}><Trash2 size={16}/></button></div></article>):<Empty title={tr("No categories yet")} text={tr("Create categories such as Supplies, Rent or Other income.")}/>}</section>}

 {entry&&data&&<OfficeEditor title={entry.id?tr("Edit financial entry"):tr("Add financial entry")} initial={entry} fields={[

  {name:'kind',label:tr("Type"),type:'select',required:true,options:[{value:'income',label:tr("Income")},{value:'expense',label:tr("Expense")}]},

  {name:'category_id',label:tr("Category"),type:'select',required:true,options:data.categories.filter(c=>c.active||c.id===entry.category_id).map(c=>({value:c.id,label:c.name+(!c.active?tr(" (archived)"):'')}))},

  {name:'amount',label:tr("Amount ({value1})", {value1: data.currency}),type:'number',required:true,min:1,max:1000000000,step:1},{name:'entry_date',label:tr("Entry date"),type:'date',required:true},{name:'description',label:tr("Description"),required:true,max:255},{name:'notes',label:tr("Notes (optional)"),type:'textarea',max:2000}

 ]} onClose={()=>setEntry(null)} onSave={async values=>{await api('business-finance/entries',{action:'save',kind:values.kind,category_id:Number(values.category_id),amount:Number(values.amount),entry_date:values.entry_date,description:values.description,notes:values.notes||null,...(entry.id?{id:entry.id,version:entry.version}:{})});setEntry(null);await saved(tr("Financial entry saved."));}}/>}

 {category&&<OfficeEditor title={category.id?tr("Edit financial category"):tr("Add financial category")} initial={category} fields={[{name:'name',label:tr("Category name"),required:true,max:100},{name:'active',label:tr("Available for new entries"),type:'checkbox'}]} onClose={()=>setCategory(null)} onSave={async values=>{await api('business-finance/categories',{action:'save',name:values.name,active:!!values.active,...(category.id?{id:category.id,version:category.version}:{})});setCategory(null);await saved(tr("Category saved."));}}/>}

 {deleting&&<OfficeEditor title={tr("Delete category")} initial={{}} fields={[]} submitLabel={tr("Delete category")} onClose={()=>setDeleting(null)} onSave={async()=>{await api('business-finance/categories',{action:'delete',id:deleting.id,version:deleting.version});setDeleting(null);await saved(tr("Category deleted."));}}><p>{tr("Delete the unused category")} <strong>{deleting.name}</strong>?</p></OfficeEditor>}

 {voiding&&<OfficeEditor title={tr("Void financial entry")} initial={{reason:''}} fields={[{name:'reason',label:tr("Reason"),required:true,max:255}]} submitLabel={tr("Void entry")} onClose={()=>setVoiding(null)} onSave={async values=>{await api('business-finance/entries',{action:'void',id:voiding.id,version:voiding.version,reason:values.reason});setVoiding(null);await saved(tr("Entry voided."));}}><p>{tr("Void")} <strong>{voiding.description}</strong>{tr("? It will be excluded from totals and kept in the history.")}</p></OfficeEditor>}

 </div>;

}

