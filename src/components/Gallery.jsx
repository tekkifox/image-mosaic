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
        console.error('Failed to fetch photo count:', err);
      });

    // Fetch photo tiles
    fetch('api.php?action=tiles&category=Travelling', { signal: controller.signal })
      .then((r) => r.json())
      .then((data) => {
        if (!mounted) return;
        if (data && Array.isArray(data.tiles)) {
           setColumns(data.columns || 12);
           const mapped = data.tiles.map((t) => ({
             src: t.thumb,
             full: t.full || t.link || t.thumb,
             alt: t.title || '',
             albums: t.albums || [],
             caption: t.caption || '',
             taken: t.taken || '',
             imageHash: t.imageHash,  // Full-size image hash
             mediumHash: t.mediumHash, // Medium image hash
           }));
           setItems(mapped);
         }
      })
      .catch((err) => {
        if (err.name === 'AbortError') return;
        console.error(err);
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });

    return () => {
      mounted = false;
      controller.abort();
    };
  }, []);

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
