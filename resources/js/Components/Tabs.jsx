import React from 'react';
import './Tabs.css';

/**
 * SSOT Reusable Tabs Navigation Component
 *
 * @param {Array<{ key: string, label: string|React.ReactNode, icon?: React.ComponentType, badge?: string|number }>} tabs
 * @param {string} activeTab - Currently active tab key
 * @param {(tabKey: string) => void} onChange - Tab change handler
 * @param {'line' | 'pills' | 'enclosed'} [variant='line'] - Visual styling variant
 * @param {'horizontal' | 'vertical'} [orientation='horizontal'] - A column of
 *   tabs beside the panel rather than a row above it. Worth it once there are
 *   enough of them that a row either wraps or scrolls, and a long label like
 *   "Announcement Ticker" stops fitting: down the side there is room to read
 *   them all at once. Falls back to a row on a narrow screen, where there is
 *   no width to give away.
 * @param {string} [className] - Additional custom CSS class
 * @param {boolean} [navigation=false] - Jump to sections that are all on the
 *   page, rather than switch between panels only one of which is shown.
 *
 *   This changes what the row *is*, so it changes the markup rather than only
 *   the styling. A `role="tab"` promises a `tabpanel` that appears when it is
 *   chosen and hides when it is not; pointing that at four headings a reader
 *   can already see says the opposite of what is true, and `aria-selected`
 *   claims the other three are hidden. In this mode it is a `nav` of real
 *   links to fragments, with `aria-current` marking the one being read — which
 *   also means they work before the JavaScript runs, and can be opened in a
 *   new tab or bookmarked like any other link.
 */
export default function Tabs({
    tabs = [],
    activeTab,
    onChange,
    variant = 'line',
    orientation = 'horizontal',
    className = '',
    navigation = false,
}) {
    const Container = navigation ? 'nav' : 'div';

    return (
        <div
            className={`reusable-tabs-container variant-${variant} orientation-${orientation} ${className}`}
        >
            <Container
                className="reusable-tabs-nav"
                role={navigation ? undefined : 'tablist'}
                aria-orientation={navigation ? undefined : orientation}
            >
                {tabs.map((tab) => {
                    const isActive = activeTab === tab.key;
                    const Icon = tab.icon;

                    const inner = (
                        <>
                            {Icon && (
                                <Icon size={16} className="reusable-tab-icon" />
                            )}
                            <span className="reusable-tab-label">
                                {tab.label}
                            </span>
                            {tab.badge !== undefined && tab.badge !== null && (
                                <span
                                    className={`reusable-tab-badge ${isActive ? 'badge-active' : ''}`}
                                >
                                    {tab.badge}
                                </span>
                            )}
                        </>
                    );

                    if (navigation) {
                        return (
                            <a
                                key={tab.key}
                                href={`#${tab.key}`}
                                aria-current={isActive ? 'true' : undefined}
                                className={`reusable-tab-btn ${isActive ? 'active' : ''}`}
                                onClick={(event) => {
                                    // The href stays the fallback; smooth
                                    // scrolling and the active mark are this.
                                    event.preventDefault();
                                    onChange(tab.key);
                                }}
                            >
                                {inner}
                            </a>
                        );
                    }

                    return (
                        <button
                            key={tab.key}
                            type="button"
                            role="tab"
                            aria-selected={isActive}
                            className={`reusable-tab-btn ${isActive ? 'active' : ''}`}
                            onClick={() => onChange(tab.key)}
                        >
                            {inner}
                        </button>
                    );
                })}
            </Container>
        </div>
    );
}
