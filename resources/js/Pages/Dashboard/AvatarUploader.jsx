import React, { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { Camera, Trash2 } from 'lucide-react';
import { ImageCropperModal } from '@/Components/ImageCropperModal';
import { toast } from '@/Components/Toast';
import { API_ENDPOINTS } from '@/constants/endpoints';

/**
 * The customer's profile picture.
 *
 * users.avatar had been in the schema since the first migration with nothing
 * ever writing to it, so every screen drew the initial of the customer's name
 * instead. The cropper the admin already uses for product shots does the work
 * here — square, and downscaled before it is sent, so a 12MP phone photo does
 * not travel over a Bangladeshi mobile connection at full size.
 */
export default function AvatarUploader({ user }) {
    const fileRef = useRef(null);
    const [source, setSource] = useState(null);
    const [busy, setBusy] = useState(false);

    const initial = user?.name ? user.name.charAt(0).toUpperCase() : 'U';

    const pickFile = (event) => {
        const file = event.target.files?.[0];

        if (!file) return;

        const reader = new FileReader();
        reader.onload = () => setSource(reader.result);
        reader.readAsDataURL(file);

        // So choosing the same file twice still opens the cropper.
        event.target.value = '';
    };

    const upload = ({ blob }) => {
        if (!blob) return;

        setBusy(true);
        setSource(null);

        const data = new FormData();
        data.append('avatar', blob, 'avatar.jpg');

        router.post(API_ENDPOINTS.ACCOUNT.AVATAR, data, {
            preserveScroll: true,
            forceFormData: true,
            onError: (errors) =>
                toast.error(
                    errors?.avatar || 'We could not save that picture.',
                ),
            onFinish: () => setBusy(false),
        });
    };

    const remove = () => {
        if (!window.confirm('Remove your profile picture?')) return;

        setBusy(true);
        router.delete(API_ENDPOINTS.ACCOUNT.AVATAR, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="account-avatar">
            <div className={`account-avatar-disc${busy ? ' is-busy' : ''}`}>
                {user?.avatar ? (
                    <img src={user.avatar} alt={user?.name || 'Profile'} />
                ) : (
                    <span>{initial}</span>
                )}

                <button
                    type="button"
                    className="account-avatar-change"
                    onClick={() => fileRef.current?.click()}
                    disabled={busy}
                    title="Change profile picture"
                    aria-label="Change profile picture"
                >
                    <Camera size={14} />
                </button>
            </div>

            {user?.avatar && (
                <button
                    type="button"
                    className="account-avatar-remove"
                    onClick={remove}
                    disabled={busy}
                >
                    <Trash2 size={12} /> Remove
                </button>
            )}

            <input
                ref={fileRef}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                onChange={pickFile}
                hidden
            />

            <ImageCropperModal
                isOpen={Boolean(source)}
                onClose={() => setSource(null)}
                onCropComplete={upload}
                imageSrc={source}
                title="Crop your profile picture"
                aspectRatio={1}
                targetWidth={512}
                targetHeight={512}
                maxSizeMB={3}
            />
        </div>
    );
}
