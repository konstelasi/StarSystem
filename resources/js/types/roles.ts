/** One ability on the role form, and whether the person editing may give it. */
export type RoleAbility = {
    key: string;
    label: string;
    held: boolean;
    /**
     * Set when the ability can also be given for only the entries a person
     * created. The row then offers None, Own and All in place of a box.
     */
    own?: { key: string; label: string; held: boolean };
};

/** An ability a role can hold for one model. Whether it may be given differs per model. */
export type ModelAbilityRow = {
    key: string;
    label: string;
    own?: { key: string; label: string };
};

/** A model on the role form, with the abilities the person editing may give for it. */
export type ModelChoice = {
    id: number;
    label: string;
    group: string | null;
    held: string[];
};
