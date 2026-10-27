import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Caption from '@components/Caption';

describe('Caption', () => {
  it('renders nothing when there is no caption, albums or date', () => {
    const { container } = render(<Caption caption="" albums={[]} taken="" />);

    expect(container).toBeEmptyDOMElement();
  });

  it('renders only the fields that are present', () => {
    render(<Caption caption="Sunset" albums={[]} taken="" />);

    expect(screen.getByText('Sunset')).toBeInTheDocument();
    expect(screen.queryByText('Taken')).not.toBeInTheDocument();
  });

  it('renders every album as a pill', () => {
    render(<Caption caption="" albums={['Japan', 'Favourites']} taken="2016-03-04" />);

    expect(screen.getByText('Japan')).toHaveClass('km-album-pill');
    expect(screen.getByText('Favourites')).toHaveClass('km-album-pill');
    expect(screen.getByText('2016-03-04')).toBeInTheDocument();
  });
});
