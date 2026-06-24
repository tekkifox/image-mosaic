// Buildless React gallery mounted from index.php
(function(React, ReactDOM){
  const {useState, useEffect} = React;

  function Lightbox({items, currentIndex, onClose, onPrev, onNext}){
    useEffect(()=>{
      function onKey(e){
        if(e.key==='Escape') onClose();
        if(e.key==='ArrowLeft') onPrev();
        if(e.key==='ArrowRight') onNext();
      }
      document.addEventListener('keydown', onKey);
      document.body.style.overflow = 'hidden';
      return ()=>{ document.removeEventListener('keydown', onKey); document.body.style.overflow = ''; };
    }, [onClose, onPrev, onNext]);

    if(currentIndex<0 || currentIndex>=items.length) return null;
    const it = items[currentIndex];

    return React.createElement('div',{className:'km-lightbox-backdrop km-open', onClick: (e)=>{ if(e.target===e.currentTarget) onClose(); }},
      React.createElement('div',{className:'km-lightbox-content'},
        React.createElement('button',{className:'km-lightbox-close', onClick: onClose, 'aria-label':'Close'}, '✕'),
        React.createElement('button',{className:'km-lightbox-nav km-lightbox-prev', onClick: onPrev, 'aria-label':'Previous'}, '◀'),

        // image wrapper ensures caption overlays the image itself
        React.createElement('div',{className:'km-lightbox-image-wrap'},
          React.createElement('img',{src: it.full || it.src, alt: it.alt || ''}),
          React.createElement('div',{className:'km-lightbox-caption'}, it.caption || '')
        ),

        React.createElement('button',{className:'km-lightbox-nav km-lightbox-next', onClick: onNext, 'aria-label':'Next'}, '▶')
      )
    );
  }

  function Gallery(){
    const [items, setItems] = useState([]);
    const [columns, setColumns] = useState(12);
    const [openIndex, setOpenIndex] = useState(-1);
    const [loading, setLoading] = useState(true);

    useEffect(()=>{
      let mounted = true;
      // Start fetch and set an early skeleton based on expected columns
      const controller = new AbortController();
      fetch('api.php?action=tiles', {signal: controller.signal}).then(r=>r.json()).then(data=>{
        if(!mounted) return;
        if(data && Array.isArray(data.tiles)){
          setColumns(data.columns || 12);
          const mapped = data.tiles.map(t=>({src:t.thumb, full: t.full || t.link || t.thumb, alt: t.title || '', caption: (t.albums||[]).join(', ')}));
          setItems(mapped);
        }
      }).catch(err=>{
        if(err.name==='AbortError') return;
        console.error(err);
      }).finally(()=>{ if(mounted) setLoading(false); });

      return ()=>{ mounted=false; controller.abort(); };
    }, []);

    // progressive image loading: replace src with low-res placeholder then high-res when visible
    useEffect(()=>{
      if(items.length===0) return;
      const imgs = document.querySelectorAll('#mosaic img[data-src]');
      const io = new IntersectionObserver((entries)=>{
        entries.forEach(entry=>{
          if(entry.isIntersecting){
            const img = entry.target;
            img.src = img.dataset.src;
            img.removeAttribute('data-src');
            io.unobserve(img);
          }
        });
      }, {rootMargin:'200px'});
      imgs.forEach(i=>io.observe(i));
      return ()=> io.disconnect();
    }, [items]);

    // Render skeleton tiles when loading to match API speed
    const skeletonCount = 12;

    return React.createElement('div', null,
      React.createElement('div',{id:'status', className:'loader', style:{display: loading ? 'block' : 'none'}}, 'Loading mosaic...'),
      React.createElement('div',{id:'mosaic', style:{display:'grid', gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))`, gap:0}},
        (items.length? items : Array.from({length: skeletonCount})).map((it, i)=>React.createElement('div',{key:i, className:'tile'},
          React.createElement('a',{href:'#', onClick:(e)=>{e.preventDefault(); if(items.length) setOpenIndex(i); }},
            React.createElement('img', it? {src: it.src, 'data-src': it.src, alt: it.alt, loading:'lazy'} : {src:'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', alt:'loading'}),
            it && it.caption? React.createElement('div',{className:'album-overlay'}, it.caption): null
          )
        ))
      ),
      openIndex>=0 && React.createElement(Lightbox, {items, currentIndex: openIndex, onClose: ()=>setOpenIndex(-1), onPrev: ()=>setOpenIndex((openIndex-1+items.length)%items.length), onNext: ()=>setOpenIndex((openIndex+1)%items.length)})
    );
  }

  const root = document.getElementById('react-mosaic-root');
  if(root && window.React && window.ReactDOM){
    ReactDOM.createRoot(root).render(React.createElement(Gallery));
  }
})(window.React, window.ReactDOM);
