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
    // Use medium hash for prefetch (faster), fallback to full-size
    const imageHash = it.mediumHash || it.imageHash;
    
    if (!imageHash || prefetchCache[index]) return; // Already prefetching or cached
    
    // Mark as prefetching to avoid duplicate requests
    setPrefetchCache(prev => ({ ...prev, [index]: 'prefetching' }));
    
    // Use hash to request image (URL is looked up server-side)
    const cacheUrl = `/api.php?action=cache&subaction=get&hash=${encodeURIComponent(imageHash)}`;
    
    // Silently prefetch in background (no UI updates)
    // Use low priority to not interfere with user interactions
    fetch(cacheUrl, { method: 'GET', priority: 'low' })
      .then(response => response.ok ? response.blob() : null)
      .then(blob => {
        if (blob) {
          const blobUrl = URL.createObjectURL(blob);
          setPrefetchCache(prev => ({ ...prev, [index]: blobUrl }));
        }
      })
      .catch(() => {
        // Silently fail, don't block anything
      });
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

  // Cache image when item changes
  useEffect(() => {
    if (currentIndex < 0 || currentIndex >= items.length) return;

    const it = items[currentIndex];
    // Use full-size hash for lightbox (imageHash), fallback to medium if not available
    const fullImageHash = it.imageHash || it.mediumHash;
    const fallbackUrl = it.src; // Fallback to thumbnail if caching fails

    if (!fullImageHash) {
      setDisplayImage(fallbackUrl || null);
      setIsLoading(false);
      return;
    }

    // Start with thumbnail/medium version if available
    if (fallbackUrl?.startsWith('data:')) {
      setDisplayImage(fallbackUrl);
    }

    // Check if already cached (from prefetch or previous view)
    if (prefetchCache[currentIndex]) {
      setDisplayImage(prefetchCache[currentIndex]);
      setIsLoading(false);
      return;
    }

    // Download full-size image from cache
    setIsLoading(true);
    
    const cacheUrl = `/api.php?action=cache&subaction=get&hash=${encodeURIComponent(fullImageHash)}`;
    
    fetch(cacheUrl, { 
      method: 'GET',
      headers: {
        'Accept': 'image/*'
      }
    })
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`);
        }
        
        return response.blob();
      })
      .then(blob => {
        if (!blob || blob.size === 0) {
          throw new Error('Empty response blob');
        }
        
        // Create blob URL for the full-size image
        const blobUrl = URL.createObjectURL(blob);
        
        // Store in cache for fast retrieval
        setPrefetchCache(prev => ({ ...prev, [currentIndex]: blobUrl }));
        
        // Display the full-size image
        setDisplayImage(blobUrl);
        setIsLoading(false);
      })
      .catch(error => {
        // Fallback to thumbnail if full-size fails
        setDisplayImage(fallbackUrl);
        setIsLoading(false);
      });
    
    // Cleanup blob URL on unmount or change
    return () => {
      // Will cleanup old blob URLs as new ones are created
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
          
          {/* Main image - displays from cache with smooth transition */}
          <img 
            key={`img-${currentIndex}`}
            src={displayImage || it.src} 
            alt={it.alt || ''} 
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
