import React, { useState, useEffect } from 'react';

import { mainLayout } from '../../Layouts/MainLayout';
import { storeService } from '../../services';
import SEOHead from '../../Components/SEOHead';
import { CardGridSkeleton } from '../../Components/Skeleton';
import siteConfig from '../../constants/siteConfig';

import {
    MapPin,
    Phone,
    Clock,
    Mail,
    ShieldCheck,
    Navigation,
} from 'lucide-react';
import './Stores.css';

export default function StoresIndex() {
    const [stores, setStores] = useState([]);
    const [loading, setLoading] = useState(true);
    const [selectedCity, setSelectedCity] = useState('all');

    useEffect(() => {
        storeService
            .getStores()
            .then((data) => {
                if (data && Array.isArray(data)) {
                    setStores(data);
                }
            })
            .catch((err) => console.error('Failed to load stores', err))
            .finally(() => setLoading(false));
    }, []);

    const cities = ['all', ...new Set(stores.map((s) => s.city))];
    const filteredStores =
        selectedCity === 'all'
            ? stores
            : stores.filter((s) => s.city === selectedCity);

    return (
        <>
            <SEOHead
                title="Our Showrooms & Service Centers"
                description={`Find ${siteConfig.name} official showrooms, flagship outlets, and authorized customer warranty service centers nationwide.`}
            />

            <div className="stores-page-wrapper">
                <div className="container">
                    {/* Header Banner */}
                    <div className="stores-header-card">
                        <span className="stores-badge">
                            NATIONWIDE PRESENCE
                        </span>
                        <h1>Showrooms &amp; Authorized Experience Centers</h1>
                        <p>
                            Visit any of our official outlets across Bangladesh
                            for live hardware demonstrations, instant PC
                            assembly, official brand warranty claims, and expert
                            consultation.
                        </p>

                        {/* City Filter Pills */}
                        <div className="city-filter-strip">
                            {cities.map((city) => (
                                <button
                                    key={city}
                                    type="button"
                                    className={`city-pill ${selectedCity === city ? 'active' : ''}`}
                                    onClick={() => setSelectedCity(city)}
                                >
                                    {city === 'all' ? 'All Outlets' : city}
                                </button>
                            ))}
                        </div>
                    </div>

                    {loading ? (
                        <CardGridSkeleton count={3} className="stores-grid" />
                    ) : (
                        <div className="stores-grid">
                            {filteredStores.map((store) => (
                                <div key={store.id} className="store-card">
                                    <div className="store-card-header">
                                        <div className="store-type-tag">
                                            <ShieldCheck size={13} />
                                            <span>{store.branch_type}</span>
                                        </div>
                                        <span className="store-city-label">
                                            {store.city}
                                        </span>
                                    </div>

                                    <h3 className="store-name">{store.name}</h3>

                                    <div className="store-details-list">
                                        <div className="store-detail-row">
                                            <MapPin
                                                size={16}
                                                className="detail-icon"
                                            />
                                            <span>{store.address}</span>
                                        </div>

                                        <div className="store-detail-row">
                                            <Phone
                                                size={16}
                                                className="detail-icon"
                                            />
                                            <a
                                                href={`tel:${store.phone}`}
                                                className="store-phone-link"
                                            >
                                                {store.phone}
                                            </a>
                                        </div>

                                        {store.email && (
                                            <div className="store-detail-row">
                                                <Mail
                                                    size={16}
                                                    className="detail-icon"
                                                />
                                                <a
                                                    href={`mailto:${store.email}`}
                                                    className="store-email-link"
                                                >
                                                    {store.email}
                                                </a>
                                            </div>
                                        )}

                                        <div className="store-detail-row">
                                            <Clock
                                                size={16}
                                                className="detail-icon"
                                            />
                                            <span>{store.opening_hours}</span>
                                        </div>
                                    </div>

                                    <div className="store-card-actions">
                                        <a
                                            href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(store.name + ' ' + store.address)}`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="store-map-btn"
                                        >
                                            <Navigation size={14} />
                                            <span>Get Directions</span>
                                        </a>
                                        <a
                                            href={`tel:${store.phone}`}
                                            className="store-call-btn"
                                        >
                                            <Phone size={14} />
                                            <span>Call Branch</span>
                                        </a>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

// Persistent shell: mounts once, survives navigation.
StoresIndex.layout = mainLayout;
