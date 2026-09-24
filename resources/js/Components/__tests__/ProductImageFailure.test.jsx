import React from 'react';
import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import ProductImage from '../ProductImage';

/**
 * What a product picture falls back to.
 *
 * The photo failing swaps in the placeholder, as before. The placeholder
 * failing too — a dropped connection, a server too busy to answer — left a
 * broken <img>, and the browser painted its alt text across the card: the
 * product's name in body type, under the sale badge. It is an empty box of the
 * same size now, still named for a screen reader.
 */
describe('ProductImage when loading fails', () => {
    const product = {
        name: 'ASUS Vivobook Go 15',
        image_url: '/storage/uploads/products/missing.jpg',
    };

    it('tries the placeholder when the photo fails', () => {
        render(<ProductImage product={product} className="card-img" />);

        const img = screen.getByRole('img', { name: 'ASUS Vivobook Go 15' });
        fireEvent.error(img);

        expect(
            screen.getByRole('img', { name: 'ASUS Vivobook Go 15' }),
        ).toHaveAttribute('src', '/images/product-placeholder.svg');
    });

    it('draws an empty named box when the placeholder fails too', () => {
        const { container } = render(
            <ProductImage product={product} className="card-img" />,
        );

        fireEvent.error(screen.getByRole('img'));
        fireEvent.error(screen.getByRole('img'));

        const box = screen.getByRole('img', { name: 'ASUS Vivobook Go 15' });
        expect(box.tagName).toBe('SPAN');
        expect(box).toHaveClass('card-img', 'product-image-failed');
        expect(box).toBeEmptyDOMElement();
        expect(container.querySelector('img')).toBeNull();
    });

    /*
     * The server sends the placeholder itself for a product whose photo is
     * missing. Falling back to the same URL changed nothing, no second error
     * ever came, and the card kept its broken image — the case that was seen.
     */
    it('goes straight to the box when the placeholder was the first try', () => {
        render(
            <ProductImage
                product={{
                    name: 'Sample Jiayou',
                    image_url: '/images/product-placeholder.svg',
                }}
            />,
        );

        fireEvent.error(screen.getByRole('img'));

        expect(screen.getByRole('img', { name: 'Sample Jiayou' }).tagName).toBe(
            'SPAN',
        );
    });

    /* As the API actually sends it: absolute, while the fallback is a path. */
    it('knows an absolute placeholder URL is the same placeholder', () => {
        render(
            <ProductImage
                product={{
                    name: 'Sample Jiayou',
                    image_url: `${window.location.origin}/images/product-placeholder.svg`,
                }}
            />,
        );

        fireEvent.error(screen.getByRole('img'));

        expect(screen.getByRole('img', { name: 'Sample Jiayou' }).tagName).toBe(
            'SPAN',
        );
    });

    it('tries again when it is given a different product', () => {
        const { rerender } = render(<ProductImage product={product} />);
        fireEvent.error(screen.getByRole('img'));
        fireEvent.error(screen.getByRole('img'));

        rerender(
            <ProductImage
                product={{ name: 'Dell Pro 15', image_url: '/images/dell.jpg' }}
            />,
        );

        expect(
            screen.getByRole('img', { name: 'Dell Pro 15' }),
        ).toHaveAttribute('src', '/images/dell.jpg');
    });
});
