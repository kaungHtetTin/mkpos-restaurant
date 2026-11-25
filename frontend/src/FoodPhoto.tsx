import { tr, useLocale } from './locale';
import { useEffect, useRef, useState } from 'react';
import { Camera, Crop, ImagePlus, Trash2 } from 'lucide-react';
import { SquarePhotoCropper } from './SquarePhotoCropper';
import { SquarePhotoCamera } from './SquarePhotoCamera';

export function FoodPhoto({ url, emoji, name }: { url?: string | null; emoji: string; name: string }) {
  useLocale();
  const [failed, setFailed] = useState(false);
  useEffect(() => setFailed(false), [url]);
  return url && !failed
    ? <img className="food-photo" src={url} alt={name} loading="lazy" onError={() => setFailed(true)}/>
    : <span>{emoji}</span>;
}

export function FoodPhotoInput({ existingUrl, file, removed, disabled, onChange, onEditingChange }: {
  existingUrl?: string | null;
  file: File | null;
  removed: boolean;
  disabled: boolean;
  onChange: (file: File | null, removed: boolean) => void;
  onEditingChange: (editing: boolean) => void;
}) {
  useLocale();
  const [preview, setPreview] = useState<string | null>(null);
  const [error, setError] = useState('');
  const input = useRef<HTMLInputElement>(null);
  const [cropSource, setCropSource] = useState<File | null>(null);
  const [loadingCrop, setLoadingCrop] = useState(false);
  const [cameraOpen, setCameraOpen] = useState(false);
  const originalSource = useRef<File | null>(null);
  useEffect(() => {
    if (!file) { setPreview(null); return; }
    const url = URL.createObjectURL(file); setPreview(url);
    return () => URL.revokeObjectURL(url);
  }, [file]);
  const url = preview || (!removed ? existingUrl : null);
  const startCrop = (source: File) => { setCropSource(source); onEditingChange(true); setError(''); };
  const adjustCrop = async () => {
    if (originalSource.current || file) { startCrop(originalSource.current || file!); return; }
    if (!existingUrl) return;
    setLoadingCrop(true); onEditingChange(true); setError('');
    try {
      const response = await fetch(existingUrl, { credentials: 'same-origin' });
      if (!response.ok) throw new Error(tr("The current photo could not be loaded. Please upload it again."));
      const blob = await response.blob();
      startCrop(new File([blob], 'food-photo', { type: blob.type }));
    } catch (exception) { setError((exception as Error).message); onEditingChange(false); }
    finally { setLoadingCrop(false); }
  };
  return <div className="food-photo-input">
    <div className="row-between"><strong>{tr("Food photo")} <span className="optional">{tr("Optional")}</span></strong>{url && !cropSource && !cameraOpen && <button className="text-button danger-text" type="button" disabled={disabled || loadingCrop} onClick={() => { onChange(null, true); originalSource.current = null; setError(''); if (input.current) input.current.value = ''; }}><Trash2 size={14}/>{tr("Remove photo")}</button>}</div>
    {cameraOpen ? <SquarePhotoCamera onCapture={captured => { originalSource.current = captured; onChange(captured, false); setCameraOpen(false); onEditingChange(false); }} onCancel={() => { setCameraOpen(false); onEditingChange(false); }}/> : cropSource ? <SquarePhotoCropper key={`${cropSource.name}-${cropSource.lastModified}`} source={cropSource} onApply={cropped => { originalSource.current = cropSource; onChange(cropped, false); setCropSource(null); onEditingChange(false); }} onCancel={() => { setCropSource(null); onEditingChange(false); }}/>
      : <><div className={`photo-preview ${url ? 'has-photo' : ''}`}>{url ? <img src={url} alt={tr("Food photo preview")}/> : <><ImagePlus size={28}/><span>{tr("Add a photo of this dish")}</span></>}</div>{url && <button className="button crop-existing" type="button" disabled={disabled || loadingCrop} onClick={() => void adjustCrop()}><Crop size={16}/>{loadingCrop ? tr("Opening photo...") : tr("Adjust crop")}</button>}</>}
    {!cameraOpen && !cropSource && <button className="button camera-open" type="button" disabled={disabled || loadingCrop} onClick={() => { setCameraOpen(true); onEditingChange(true); setError(''); }}><Camera size={17}/>{tr("Take photo")}</button>}
    <label>{tr("Choose food photo")}<input ref={input} type="file" accept="image/jpeg,image/png,image/webp" disabled={disabled || cameraOpen || !!cropSource || loadingCrop} onChange={e => {
      const selected = e.target.files?.[0]; if (!selected) return;
      if (selected.size > 5 * 1024 * 1024) { setError(tr("Choose a photo smaller than 5 MB.")); e.target.value = ''; return; }
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(selected.type) && (selected.type || !/\.(jpe?g|png|webp)$/i.test(selected.name))) { setError(tr("Choose a JPG, PNG, or WebP photo.")); e.target.value = ''; return; }
      startCrop(selected); e.target.value = '';
    }}/></label>
    <span className="field-hint">{tr("JPG, PNG, or WebP · Up to 5 MB. Crop to a 1:1 square, then save the menu item.")}</span>
    {removed && existingUrl && !file && <p className="photo-removal-note">{tr("The current photo will be removed when you save.")}</p>}
    {error && <div className="form-error" role="alert">{tr(error)}</div>}
  </div>;
}
