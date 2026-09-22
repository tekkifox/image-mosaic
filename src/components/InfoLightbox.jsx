import React, { useEffect } from 'react';

const InfoLightbox = ({ item, onClose }) => {
  useEffect(() => {
    const handleKeydown = (e) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', handleKeydown);
    document.body.style.overflow = 'hidden';

    return () => {
      document.removeEventListener('keydown', handleKeydown);
      document.body.style.overflow = '';
    };
  }, [onClose]);

  if (!item) return null;

  return (
    <div
      className="km-lightbox-backdrop km-open km-info-lightbox"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="km-info-lightbox-content">
        <button
          className="km-lightbox-close"
          onClick={onClose}
          aria-label="Close"
        >
          ✕
        </button>

        <h3>{item.alt || 'Image Info'}</h3>

        {item.caption && (
          <div>
            <h4>Caption:</h4>
            <p>{item.caption}</p>
          </div>
        )}

        {item.albums && item.albums.length > 0 && (
          <div>
            <h4>Albums:</h4>
            <ul>
              {item.albums.map((album, idx) => (
                <li key={idx}>{album}</li>
              ))}
            </ul>
          </div>
        )}

        {item.taken && (
          <div>
            <h4>Taken:</h4>
            <p>{item.taken}</p>
          </div>
        )}
      </div>
    </div>
  );
};

export default InfoLightbox;
