import React from 'react';
import { Loader2 } from 'lucide-react';

/**
 * Reusable Loading Spinner Component
 */
export const Spinner = ({
    size = 24,
    text = '',
    fullHeight = false,
    className = '',
}) => {
    return (
        <div
            className={`loading-spinner-wrapper ${fullHeight ? 'full-height' : ''} ${className}`.trim()}
        >
            <Loader2 size={size} className="animate-spin" />
            {text && <span className="loading-spinner-text">{text}</span>}
        </div>
    );
};

export default Spinner;
