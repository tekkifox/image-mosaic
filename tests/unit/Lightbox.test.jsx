import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Lightbox from '@components/Lightbox';

const items = [
  {
    src: 'https://images.example/thumb-1.jpg',
    medium: 'https://images.example/medium-1.jpg',
    full: 'https://images.example/full-1.jpg',
    alt: 'Tokyo',
    caption: 'Shibuya crossing',
    albums: ['Japan 2016', 'Favourites'],
    taken: '2016-03-04',
  },
  {
    src: 'https://images.example/thumb-2.jpg',
    medium: 'https://images.example/medium-2.jpg',
    full: 'https://images.example/full-2.jpg',
    alt: 'Kyoto',
    caption: '',
    albums: [],
    taken: '',
  },
];

describe('Lightbox', () => {
  it('shows the caption, albums and date for the current photo', () => {
    render(
      <Lightbox
        items={items}
        currentIndex={0}
        onClose={() => {}}
        onPrev={() => {}}
        onNext={() => {}}
      />
    );

    expect(screen.getByText('Shibuya crossing')).toBeInTheDocument();
    expect(screen.getByText('Japan 2016')).toBeInTheDocument();
    expect(screen.getByText('Favourites')).toBeInTheDocument();
    expect(screen.getByText('2016-03-04')).toBeInTheDocument();
    expect(screen.getByAltText('Tokyo')).toHaveAttribute(
      'src',
      'https://images.example/medium-1.jpg'
    );
  });

  it('maps Escape to close and arrow keys to previous/next', () => {
    const onClose = vi.fn();
    const onPrev = vi.fn();
    const onNext = vi.fn();

    render(
      <Lightbox
        items={items}
        currentIndex={1}
        onClose={onClose}
        onPrev={onPrev}
        onNext={onNext}
      />
    );

    fireEvent.keyDown(document, { key: 'Escape' });
    fireEvent.keyDown(document, { key: 'ArrowLeft' });
    fireEvent.keyDown(document, { key: 'ArrowRight' });

    expect(onClose).toHaveBeenCalledTimes(1);
    expect(onPrev).toHaveBeenCalledTimes(1);
    expect(onNext).toHaveBeenCalledTimes(1);
  });

  it('hides navigation controls when there is only one photo', () => {
    render(
      <Lightbox
        items={[items[0]]}
        currentIndex={0}
        onClose={() => {}}
        onPrev={() => {}}
        onNext={() => {}}
      />
    );

    expect(screen.queryByRole('button', { name: 'Previous' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Next' })).not.toBeInTheDocument();
  });

  it('locks body scroll while open and restores it on unmount', () => {
    document.body.style.overflow = 'auto';
    const { unmount } = render(
      <Lightbox
        items={items}
        currentIndex={0}
        onClose={() => {}}
        onPrev={() => {}}
        onNext={() => {}}
      />
    );

    expect(document.body.style.overflow).toBe('hidden');

    unmount();

    expect(document.body.style.overflow).toBe('');
  });
});
