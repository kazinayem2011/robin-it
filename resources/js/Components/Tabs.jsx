import React from 'react';
import './Tabs.css';

/**
 * SSOT Reusable Tabs Navigation Component
 *
 * @param {Array<{ key: string, label: string|React.ReactNode, icon?: React.ComponentType, badge?: string|number }>} tabs
 * @param {string} activeTab - Currently active tab key
 * @param {(tabKey: string) => void} onChange - Tab change handler
 * @param {'line' | 'pills' | 'enclosed'} [variant='line'] - Visual styling variant
 * @param {string} [className] - Additional custom CSS class
 */
export default function Tabs({
    tabs = [],
    activeTab,
    onChange,
    variant = 'line',
    className = '',
}) {
    return (
        <div
            className={`reusable-tabs-container variant-${variant} ${className}`}
        >
            <div className="reusable-tabs-nav" role="tablist">
                {tabs.map((tab) => {
                    const isActive = activeTab === tab.key;
                    const Icon = tab.icon;

                    return (
                        <button
                            key={tab.key}
                            type="button"
                            role="tab"
                            aria-selected={isActive}
                            className={`reusable-tab-btn ${isActive ? 'active' : ''}`}
                            onClick={() => onChange(tab.key)}
                        >
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
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
