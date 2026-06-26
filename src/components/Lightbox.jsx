import React, { useEffect, useState } from 'react';
import Caption from './Caption';

const Lightbox = ({ items, currentIndex, onClose, onPrev, onNext }) => {
  const [displayImage, setDisplayImage] = useState(null);
  const [isLoading, setIsLoading] = useState(false);
  const [prefetchCache, setPrefetchCache] = useState({});

  useEffect(() => {
    const handleKeydown = (e) => {
      if (e.key === 'Escape') onClose();
      if (e.key === 'ArrowLeft') onPrev();
      if (e.key === 'ArrowRight') onNext();
    };
    document.addEventListener('keydown', handleKeydown);
    document.body.style.overflow = 'hidden';

    return () => {
      document.removeEventListener('keydown', handleKeydown);
      document.body.style.overflow = '';
    };
  }, [onClose, onPrev, onNext]);

  // Prefetch adjacent images in background
  const prefetchImage = (index) => {
    if (index < 0 || index >= items.length) return;
    
    const it = items[index];
    const imageUrl = it.full || it.medium || it.src;
    
    if (!imageUrl || prefetchCache[index]) return; // Already prefetching or cached
    
    // Mark as prefetching to avoid duplicate requests
    setPrefetchCache(prev => ({ ...prev, [index]: 'prefetching' }));
    
    const img = new Image();
    img.decoding = 'async';
    img.loading = 'eager';
    img.src = imageUrl;
    img.onload = () => {
      setPrefetchCache(prev => ({ ...prev, [index]: imageUrl }));
    };
    img.onerror = () => {
      setPrefetchCache(prev => {
        const next = { ...prev };
        delete next[index];
        return next;
      });
    };
  };

  // Prefetch next and previous images when index changes
  useEffect(() => {
    if (currentIndex < 0 || currentIndex >= items.length) return;
    
    // Prefetch next image
    if (currentIndex + 1 < items.length) {
      prefetchImage(currentIndex + 1);
    }
    
    // Prefetch previous image
    if (currentIndex - 1 >= 0) {
      prefetchImage(currentIndex - 1);
    }
  }, [currentIndex, items.length]);

  // Show a small preview first, then swap to the larger direct URL when it loads.
  useEffect(() => {
    if (currentIndex < 0 || currentIndex >= items.length) return;

    const it = items[currentIndex];
    const previewUrl = it.medium || it.src || it.full;
    const fullUrl = it.full || previewUrl;

    if (!previewUrl) {
      setDisplayImage(null);
      setIsLoading(false);
      return;
    }

    setDisplayImage(previewUrl);

    if (!fullUrl || fullUrl === previewUrl) {
      setIsLoading(false);
      return;
    }

    setIsLoading(true);
    let cancelled = false;

    const largeImage = new Image();
    largeImage.decoding = 'async';
    largeImage.onload = () => {
      if (cancelled) return;
      setDisplayImage(fullUrl);
      setIsLoading(false);
    };
    largeImage.onerror = () => {
      if (cancelled) return;
      setIsLoading(false);
    };
    largeImage.src = fullUrl;

    // Direct image URLs are browser-cached; no manual cleanup needed.
    return () => {
      cancelled = true;
    };
  }, [currentIndex, items]);

  if (currentIndex < 0 || currentIndex >= items.length) return null;
  const it = items[currentIndex];

  return (
    <div
      className="km-lightbox-backdrop km-open"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="km-lightbox-content">
        <button
          className="km-lightbox-close"
          onClick={onClose}
          aria-label="Close"
        >
          ✕
        </button>

        {items.length > 1 && (
          <button
            className="km-lightbox-nav km-lightbox-prev"
            onClick={onPrev}
            aria-label="Previous"
          >
            ◀
          </button>
        )}

        <div 
          className="km-lightbox-image-wrap"
          style={{
            position: 'relative',
            display: 'flex',
            flexDirection: 'column',
            alignItems: 'center',
            justifyContent: 'center',
            maxWidth: '100%',
            maxHeight: 'calc(90vh - 100px)',
            width: '100%',
            overflow: 'hidden',
          }}
        >
          {/* Loading indicator overlay */}
          {isLoading && displayImage && (
            <div className="km-loading-spinner" style={{
              position: 'absolute',
              top: '50%',
              left: '50%',
              transform: 'translate(-50%, -50%)',
              zIndex: 1000,
              padding: '20px 40px',
              background: 'rgba(0, 0, 0, 0.7)',
              color: '#fff',
              borderRadius: '8px',
              fontSize: '14px',
              fontWeight: 'bold',
              textAlign: 'center',
            }}>
              <div>📥 Loading full-size...</div>
              <div style={{ fontSize: '12px', marginTop: '8px', opacity: 0.8 }}>
                (Displaying preview)
              </div>
            </div>
          )}
          
          {/* Main image - browser loads the direct PhotoPrism URL */}
          <img 
            key={`img-${currentIndex}`}
            src={displayImage || it.medium || it.src || it.full} 
            alt={it.alt || ''} 
            onError={() => setIsLoading(false)}
            style={{ 
              opacity: 1,
              maxWidth: '100%',
              maxHeight: '100%',
              objectFit: 'contain',
              borderRadius: '4px',
              boxShadow: '0 10px 30px rgba(0, 0, 0, 0.6)',
              transition: 'opacity 0.3s ease-in-out',
              width: '100%',
              height: '100%',
            }}
          />
        </div>

        <Caption
          caption={it.caption}
          albums={it.albums}
          taken={it.taken}
        />

        {items.length > 1 && (
          <button
            className="km-lightbox-nav km-lightbox-next"
            onClick={onNext}
            aria-label="Next"
          >
            ▶
          </button>
        )}
      </div>
    </div>
  );
};

export default Lightbox;
