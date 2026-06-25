import React from 'react';

const Caption = ({ caption, albums, taken }) => {
  const hasContent = caption || (albums && albums.length > 0) || taken;

  if (!hasContent) return null;

  return (
    <div className="km-lightbox-caption">
      {caption && (
        <div style={{ marginBottom: caption && albums && albums.length > 0 ? '8px' : '0' }}>
          {caption}
        </div>
      )}

      {albums && albums.length > 0 && (
        <div
          className="km-albums-container"
          style={{ marginBottom: albums.length > 0 && taken ? '8px' : '0' }}
        >
          {albums.map((album, idx) => (
            <span key={idx} className="km-album-pill">
              {album}
            </span>
          ))}
        </div>
      )}

      {taken && <div>{taken}</div>}
    </div>
  );
};

export default Caption;
