import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import Button from '@/Components/Button';
import FormInput from '@/Components/FormInput';
import Modal from '@/Components/Modal';
import { ROUTES } from '@/constants/endpoints';
import '../Profile.css';

export default function DeleteUserForm() {
    const [confirmingUserDeletion, setConfirmingUserDeletion] = useState(false);

    const {
        data,
        setData,
        delete: destroy,
        processing,
        reset,
        errors,
        clearErrors,
    } = useForm({ password: '' });

    const deleteUser = (e) => {
        e.preventDefault();

        destroy(ROUTES.PROFILE_DESTROY, {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onFinish: () => reset(),
        });
    };

    const closeModal = () => {
        setConfirmingUserDeletion(false);
        clearErrors();
        reset();
    };

    return (
        <section>
            <h2 className="profile-section-title">Delete Account</h2>
            <p className="profile-section-desc">
                Once your account is deleted, all of its data is permanently
                removed. Please download anything you want to keep first. Orders
                already placed are retained for our records.
            </p>

            <Button
                variant="danger"
                icon={AlertTriangle}
                onClick={() => setConfirmingUserDeletion(true)}
            >
                Delete Account
            </Button>

            <Modal show={confirmingUserDeletion} onClose={closeModal}>
                <form
                    onSubmit={deleteUser}
                    className="profile-delete-modal"
                    noValidate
                >
                    <h2 className="profile-section-title">
                        Delete your account?
                    </h2>

                    <p className="profile-section-desc">
                        This cannot be undone. Enter your password to confirm
                        you want to permanently delete your account.
                    </p>

                    <FormInput
                        label="Password"
                        name="password"
                        type="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        error={errors.password}
                        placeholder="Enter your password"
                        autoFocus
                    />

                    <div className="profile-delete-actions">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={closeModal}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="danger"
                            loading={processing}
                        >
                            Delete Account
                        </Button>
                    </div>
                </form>
            </Modal>
        </section>
    );
}
