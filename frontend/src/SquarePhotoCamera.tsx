import { useEffect, useRef, useState } from 'react';
import { Camera } from 'lucide-react';
import { tr, useLocale } from './locale';

export function SquarePhotoCamera({ onCapture, onCancel }: {
  onCapture: (file: File) => void;
  onCancel: () => void;
}) {
  useLocale();
  const video = useRef<HTMLVideoElement>(null);
  const [ready, setReady] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const active = useRef(false);

  useEffect(() => {
    active.current = true;
    let cancelled = false;
    let stream: MediaStream | undefined;
    const open = async () => {
      try {
        if (!navigator.mediaDevices?.getUserMedia) {
          setError(tr('Camera access requires HTTPS or localhost. You can also choose a photo below.'));
          return;
        }
        stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } } });
        if (cancelled) { stream.getTracks().forEach(track => track.stop()); return; }
        if (video.current) {
          video.current.srcObject = stream;
          await video.current.play();
        }
      } catch (exception) {
        stream?.getTracks().forEach(track => track.stop());
        if (cancelled) return;
        const name = (exception as DOMException).name?.toLowerCase();
        setError(name === 'notallowederror'
          ? tr('Camera permission was denied. Allow camera access in your browser, then try again, or choose a photo below.')
          : name === 'notfounderror'
            ? tr('No camera was found. Connect a camera or choose a photo below.')
            : tr('The camera could not be opened. Close other camera apps and try again, or choose a photo below.'));
      }
    };
    void open();
    return () => {
      cancelled = true; active.current = false;
      stream?.getTracks().forEach(track => track.stop());
      if (video.current) video.current.srcObject = null;
    };
  }, []);

  const capture = async () => {
    const element = video.current;
    if (!element || !ready || busy || !element.videoWidth || !element.videoHeight) return;
    setBusy(true); setError('');
    try {
      const side = Math.min(element.videoWidth, element.videoHeight);
      const canvas = document.createElement('canvas');
      canvas.width = canvas.height = Math.min(side, 1024);
      const context = canvas.getContext('2d');
      if (!context) throw new Error('canvas-unavailable');
      context.drawImage(element, (element.videoWidth - side) / 2, (element.videoHeight - side) / 2, side, side, 0, 0, canvas.width, canvas.height);
      const blob = await new Promise<Blob>((resolve, reject) => {
        canvas.toBlob(result => result ? resolve(result) : reject(new Error('capture-failed')), 'image/jpeg', .9);
      });
      if (active.current) onCapture(new File([blob], `food-camera-${Date.now()}.jpg`, { type: blob.type }));
    } catch {
      if (active.current) setError(tr('This photo could not be captured. Please try again.'));
    } finally { if (active.current) setBusy(false); }
  };

  return <section className="square-cropper" aria-label={tr('Food photo camera')}>
    <div className="cropper-heading"><strong><Camera size={16}/>{tr('Take food photo')}</strong><span>{tr('1:1 square')}</span></div>
    <p>{tr('Position your dish inside the square, then take the photo.')}</p>
    <div className="camera-viewport">
      <video ref={video} muted playsInline autoPlay aria-label={tr('Live camera preview')} onCanPlay={() => setReady(true)} onWaiting={() => setReady(false)}/>
      {!ready && !error && <span className="crop-loading" role="status">{tr('Opening camera...')}</span>}
      <div className="crop-grid" aria-hidden="true"><i/><i/><i/><i/></div>
    </div>
    {error && <div className="form-error" role="alert">{error}</div>}
    <div className="crop-actions camera-actions"><button className="button" type="button" onClick={onCancel}>{tr('Cancel camera')}</button><button className="button primary" type="button" disabled={!ready || busy} onClick={() => void capture()}><Camera size={16}/>{busy ? tr('Taking photo...') : tr('Capture photo')}</button></div>
  </section>;
}
