import { tr, useLocale } from './locale';
import { useEffect, useRef, useState, type PointerEvent } from 'react';
import { Crop, RotateCcw } from 'lucide-react';

const clamp = (value: number) => Math.max(-1, Math.min(1, value));

export function SquarePhotoCropper({ source, onApply, onCancel }: {
  source: File;
  onApply: (file: File) => void;
  onCancel: () => void;
}) {
  useLocale();
  const [url, setUrl] = useState('');
  const [dimensions, setDimensions] = useState({ width: 0, height: 0 });
  const [size, setSize] = useState(0);
  const [zoom, setZoom] = useState(1);
  const [position, setPosition] = useState({ x: 0, y: 0 });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const viewport = useRef<HTMLDivElement>(null);
  const image = useRef<HTMLImageElement>(null);
  const drag = useRef<{ id: number; x: number; y: number; position: typeof position } | null>(null);

  useEffect(() => {
    const objectUrl = URL.createObjectURL(source);
    setUrl(objectUrl); setDimensions({ width: 0, height: 0 }); setZoom(1); setPosition({ x: 0, y: 0 }); setError('');
    return () => URL.revokeObjectURL(objectUrl);
  }, [source]);

  useEffect(() => {
    const element = viewport.current;
    if (!element) return;
    const measure = () => setSize(element.getBoundingClientRect().width);
    measure();
    const observer = new ResizeObserver(measure); observer.observe(element);
    return () => observer.disconnect();
  }, []);

  const loaded = dimensions.width > 0 && dimensions.height > 0 && size > 0;
  const scale = loaded ? Math.max(size / dimensions.width, size / dimensions.height) * zoom : 1;
  const width = dimensions.width * scale;
  const height = dimensions.height * scale;
  const horizontalTravel = Math.max(0, (width - size) / 2);
  const verticalTravel = Math.max(0, (height - size) / 2);
  const left = -horizontalTravel + position.x * horizontalTravel;
  const top = -verticalTravel + position.y * verticalTravel;

  const move = (event: PointerEvent<HTMLDivElement>) => {
    if (!drag.current || drag.current.id !== event.pointerId) return;
    setPosition({
      x: horizontalTravel ? clamp(drag.current.position.x + (event.clientX - drag.current.x) / horizontalTravel) : 0,
      y: verticalTravel ? clamp(drag.current.position.y + (event.clientY - drag.current.y) / verticalTravel) : 0,
    });
  };

  const apply = async () => {
    if (!loaded || !image.current || busy) return;
    setBusy(true); setError('');
    try {
      const sourceSize = Math.min(dimensions.width, dimensions.height, size / scale);
      const sourceX = Math.max(0, Math.min(dimensions.width - sourceSize, -left / scale));
      const sourceY = Math.max(0, Math.min(dimensions.height - sourceSize, -top / scale));
      const outputSize = Math.max(1, Math.min(1024, Math.round(sourceSize)));
      const canvas = document.createElement('canvas');
      canvas.width = outputSize; canvas.height = outputSize;
      const context = canvas.getContext('2d');
      if (!context) throw new Error(tr("Your browser could not prepare this crop. Please try another browser."));
      context.imageSmoothingEnabled = true; context.imageSmoothingQuality = 'high';
      context.drawImage(image.current, sourceX, sourceY, sourceSize, sourceSize, 0, 0, outputSize, outputSize);
      const mime = source.type === 'image/png' ? 'image/png' : 'image/jpeg';
      const blob = await new Promise<Blob>((resolve, reject) => {
        canvas.toBlob(result => result ? resolve(result) : reject(new Error("This photo could not be cropped. Please try another image.")), mime, .9);
      });
      if (blob.size > 5 * 1024 * 1024) throw new Error(tr("The cropped image is too large. Please choose a smaller photo."));
      const extension = blob.type === 'image/png' ? 'png' : 'jpg';
      onApply(new File([blob], `${source.name.replace(/\.[^.]+$/, '')}-square.${extension}`, { type: blob.type }));
    } catch (exception) { setError((exception as Error).message); }
    finally { setBusy(false); }
  };

  return <section className="square-cropper" aria-label={tr("Square food photo cropper")}>
    <div className="cropper-heading"><strong><Crop size={16}/>{tr("Crop food photo")}</strong><span>{tr("1:1 square")}</span></div>
    <p id="crop-instructions">{tr("Drag to position your dish. Use zoom for a closer crop, or arrow keys to move it.")}</p>
    <div ref={viewport} className="crop-viewport" role="group" aria-label={tr("Photo crop area")} aria-describedby="crop-instructions" tabIndex={0}
      onPointerDown={event => {
        if (!event.isPrimary || event.button !== 0 || !loaded || busy) return;
        event.currentTarget.focus({ preventScroll: true });
        event.currentTarget.setPointerCapture(event.pointerId);
        drag.current = { id: event.pointerId, x: event.clientX, y: event.clientY, position: { ...position } };
      }}
      onPointerMove={move}
      onPointerUp={() => { drag.current = null; }}
      onPointerCancel={() => { drag.current = null; }}
      onLostPointerCapture={() => { drag.current = null; }}
      onKeyDown={event => {
        if (!loaded || busy || !["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"].includes(event.key)) return;
        event.preventDefault();
        const step = event.shiftKey ? .2 : .05;
        setPosition(previous => ({ x: clamp(previous.x + (event.key === 'ArrowLeft' ? -step : event.key === 'ArrowRight' ? step : 0)), y: clamp(previous.y + (event.key === 'ArrowUp' ? -step : event.key === 'ArrowDown' ? step : 0)) }));
      }}>
      {url && <img ref={image} src={url} alt={tr("Photo to crop")} draggable={false} style={{ width, height, left, top, visibility: loaded ? 'visible' : 'hidden' }} onLoad={event => {
        const element = event.currentTarget;
        if (element.naturalWidth > 6000 || element.naturalHeight > 6000) {
          setError(tr("Choose a photo no larger than 6000 pixels on either side.")); return;
        }
        setDimensions({ width: element.naturalWidth, height: element.naturalHeight });
      }} onError={() => setError(tr("This photo could not be opened. Choose another JPG, PNG, or WebP image."))}/>}
      {!loaded && <span className="crop-loading">{error ? tr("Cannot open photo") : tr("Opening photo...")}</span>}
      <div className="crop-grid" aria-hidden="true"><i/><i/><i/><i/></div>
    </div>
    <label className="crop-zoom">{tr("Zoom")} <output>{zoom.toFixed(2)}×</output><input aria-label={tr("Photo zoom")} type="range" min="1" max="3" step="0.05" value={zoom} disabled={!loaded || busy} onChange={event => setZoom(Number(event.target.value))}/></label>
    {error && <div className="form-error" role="alert">{tr(error)}</div>}
    <div className="crop-actions"><button className="button" type="button" disabled={!loaded || busy} onClick={() => { setZoom(1); setPosition({ x: 0, y: 0 }); }}><RotateCcw size={15}/>{tr("Reset")}</button><button className="button" type="button" disabled={busy} onClick={onCancel}>{tr("Cancel crop")}</button><button className="button primary" type="button" disabled={!loaded || busy} onClick={() => void apply()}><Crop size={16}/>{busy ? tr("Cropping...") : tr("Use cropped photo")}</button></div>
  </section>;
}
