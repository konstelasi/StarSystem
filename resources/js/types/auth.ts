export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    /** Runs updates and modules for every site. */
    installOwner: boolean;
    /** The name of the role held on this site, "Owner" for the install owner. */
    role: string | null;
    /** What the account may do on this site. */
    abilities: string[];
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
