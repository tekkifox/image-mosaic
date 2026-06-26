import React, { useState, useEffect } from 'react';
import Lightbox from './Lightbox';
import InfoLightbox from './InfoLightbox';
import Tile from './Tile';

const Gallery = () => {
  const [items, setItems] = useState([]);
  const [columns, setColumns] = useState(12);
  const [openIndex, setOpenIndex] = useState(-1);
  const [loading, setLoading] = useState(true);
  const [infoItem, setInfoItem] = useState(null);
  const [photoCount, setPhotoCount] = useState(0);
  const [totalPhotos, setTotalPhotos] = useState(0);
  const [currentOffset, setCurrentOffset] = useState(0);
  const [isLoadingMore, setIsLoadingMore] = useState(false);
  const [hasMore, setHasMore] = useState(true);
  const [loadTriggerRef, setLoadTriggerRef] = useState(null);

  const ITEMS_PER_PAGE = 18;

  const mapTile = (t) => {
    const thumbSrc = t.thumbUrl || t.thumb || t.full || '#';
    return {
      src: thumbSrc,
      full: t.full || t.link || thumbSrc,
      alt: t.title || '',
      albums: t.albums || [],
      caption: t.caption || '',
      taken: t.taken || '',
      imageHash: t.imageHash,
      mediumHash: t.mediumHash,
    };
  };

  // Load initial batch of photos and fetch total count
  useEffect(() => {
    let mounted = true;
    const controller = new AbortController();

    // Fetch photo count for stats
    fetch('api.php?action=photo-count&category=Travelling', { signal: controller.signal })
      .then((r) => r.json())
      .then((data) => {
        if (!mounted) return;
        if (data && typeof data.count === 'number') {
          setPhotoCount(data.count);
        }
      })
      .catch((err) => {
        if (err.name === 'AbortError') return;
      });

    // Fetch initial 20 tiles
    fetch(`api.php?action=tiles&category=Travelling&limit=${ITEMS_PER_PAGE}&offset=0`, { signal: controller.signal })
      .then((r) => r.json())
      .then((data) => {
        if (!mounted) return;
        if (data && Array.isArray(data.tiles)) {
          setColumns(data.columns || 12);
          setTotalPhotos(data.total || 0);
          setHasMore((data.offset || 0) + (data.tiles?.length || 0) < (data.total || 0));
          const mapped = data.tiles.map(mapTile);
          setItems(mapped);
          setCurrentOffset(ITEMS_PER_PAGE);
          setLoading(false);
        }
      })
      .catch((err) => {
        if (err.name === 'AbortError') return;
        setLoading(false);
      });

    return () => {
      mounted = false;
      controller.abort();
    };
  }, []);

  // Load more photos when user scrolls near end
  const loadMorePhotos = () => {
    if (isLoadingMore || !hasMore) {
      return;
    }

    setIsLoadingMore(true);

    fetch(`api.php?action=tiles&category=Travelling&limit=${ITEMS_PER_PAGE}&offset=${currentOffset}`)
      .then((r) => r.json())
      .then((data) => {
        if (data && Array.isArray(data.tiles) && data.tiles.length > 0) {
          const mapped = data.tiles.map(mapTile);
          setItems((prev) => [...prev, ...mapped]);
          const newOffset = currentOffset + data.tiles.length;
          setCurrentOffset(newOffset);
          setHasMore(newOffset < (data.total || totalPhotos));
        } else {
          setHasMore(false);
        }
      })
      .catch((err) => {
        if (err.name === 'AbortError') return;
      })
      .finally(() => {
        setIsLoadingMore(false);
      });
  };

  // Infinite scroll: use Intersection Observer on a trigger element
  useEffect(() => {
    if (!loadTriggerRef || !hasMore) return;

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries[0].isIntersecting && !isLoadingMore && hasMore) {
          loadMorePhotos();
        }
      },
      { rootMargin: '200px' }
    );

    observer.observe(loadTriggerRef);
    return () => observer.disconnect();
  }, [loadTriggerRef, hasMore, isLoadingMore, currentOffset]);

  // Update photo count in hero section (outside React root)
  useEffect(() => {
    if (photoCount > 0) {
      const countElement = document.querySelector('#photo-count-stat');
      if (countElement) {
        countElement.textContent = photoCount.toLocaleString();
      }
    }
  }, [photoCount]);

  // Progressive image loading
  useEffect(() => {
    if (items.length === 0) return;
    const imgs = document.querySelectorAll('#mosaic img[data-src]');
    const io = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            const img = entry.target;
            img.src = img.dataset.src;
            img.removeAttribute('data-src');
            io.unobserve(img);
          }
        });
      },
      { rootMargin: '200px' }
    );
    imgs.forEach((i) => io.observe(i));
    return () => io.disconnect();
  }, [items]);

  const skeletonCount = columns * columns;

  return (
    <div>
      <div
        id="mosaic"
        className={`mosaic-scattered ${loading ? 'mosaic-loading' : ''}`}
        style={{
          display: 'grid',
          gridTemplateColumns: `repeat(auto-fit, minmax(180px, 1fr))`,
          gap: '20px',
          padding: '20px',
          maxWidth: '1400px',
          margin: '0 auto',
        }}
      >
        {(items.length ? items : Array.from({ length: skeletonCount })).map((it, i) => (
          <Tile
            key={i}
            item={it}
            isLoading={!items.length}
            onTileClick={() => items.length && setOpenIndex(i)}
            onInfoClick={() => setInfoItem(it)}
          />
        ))}
      </div>

      {/* Infinite scroll trigger element - show skeleton placeholders while loading */}
      {hasMore && isLoadingMore && (
        <div
          style={{
            display: 'grid',
            gridTemplateColumns: `repeat(auto-fit, minmax(180px, 1fr))`,
            gap: '20px',
            padding: '20px',
            maxWidth: '1400px',
            margin: '0 auto',
          }}
        >
          {Array.from({ length: ITEMS_PER_PAGE }).map((_, i) => (
            <div key={`skeleton-${i}`} className="tile tile-scattered skeleton-tile">
              <a href="#" onClick={(e) => e.preventDefault()}>
                <div className="skeleton-img" />
              </a>
            </div>
          ))}
        </div>
      )}

      {/* Trigger element for infinite scroll */}
      <div ref={setLoadTriggerRef} style={{ height: '20px' }} />

      {openIndex >= 0 && (
        <Lightbox
          items={items}
          currentIndex={openIndex}
          onClose={() => setOpenIndex(-1)}
          onPrev={() => setOpenIndex((openIndex - 1 + items.length) % items.length)}
          onNext={() => setOpenIndex((openIndex + 1) % items.length)}
        />
      )}

      {infoItem && <InfoLightbox item={infoItem} onClose={() => setInfoItem(null)} />}
    </div>
  );
};

export default Gallery;
