import React from 'react';
import Header from '../Components/Header';
import Footer from '../Components/Footer';
import ToastContainer from '../Components/Toast';
import './MainLayout.css';

/**
 * Reusable Main Layout Component (SSOT).
 * Composes separated Modular Header, dynamic Main content, Modular Footer, and Global Toast System.
 */
export default function MainLayout({ children }) {
    return (
        <div className="main-layout-wrapper">
            {/* Modular Site Header (Top ticker, search, actions, mega menu) */}
            <Header />

            {/* Main Page Content */}
            <main className="site-main-content">{children}</main>

            {/* Modular Site Footer (Trust badges, 5-col navigation, newsletter, copyright) */}
            <Footer />

            {/* Global Toast Notifications (SSOT) */}
            <ToastContainer />
        </div>
    );
}
