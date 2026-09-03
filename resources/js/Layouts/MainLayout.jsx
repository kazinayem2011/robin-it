import React from 'react';
import Header from '../Components/Header';
import Footer from '../Components/Footer';
import ToastContainer from '../Components/Toast';
import VariantPickerModal from '../Components/VariantPickerModal';
import QuickDock from '../Components/QuickDock';
import { useFlashToasts } from '../hooks';
import './MainLayout.css';

/**
 * Reusable Main Layout Component (SSOT).
 * Composes separated Modular Header, dynamic Main content, Modular Footer, and Global Toast System.
 */
export default function MainLayout({ children }) {
    // Show whatever the server flashed on the last write.
    useFlashToasts();

    return (
        <div className="main-layout-wrapper">
            {/* Modular Site Header (Top ticker, search, actions, mega menu) */}
            <Header />

            {/* Main Page Content */}
            <main className="site-main-content">{children}</main>

            {/* Modular Site Footer (Trust badges, 5-col navigation, newsletter, copyright) */}
            <Footer />

            {/*
             * Raised from any product card on any page, so it is mounted once
             * here rather than copied into each list that renders cards.
             */}
            <VariantPickerModal />

            {/* Compare and cart, pinned to the edge rather than sitting in a
                header row that scrolls away. Mounted with the shell so it is
                in the same place on every page. */}
            <QuickDock />

            {/* Global Toast Notifications (SSOT) */}
            <ToastContainer />
        </div>
    );
}

/**
 * Use this as a page's persistent layout:
 *
 *     MyPage.layout = mainLayout;
 *
 * Every page used to render <MainLayout> inside its own tree, which meant
 * Inertia tore the whole shell down and built it again on every navigation —
 * and the header refetched the mega menu, the site settings and the cart count
 * each time. As a persistent layout it mounts once and survives page changes,
 * so those three requests happen on the first visit and not again.
 */
export const mainLayout = (page) => <MainLayout>{page}</MainLayout>;
