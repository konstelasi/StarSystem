<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Monitor, Smartphone } from '@lucide/vue';
import { computed, ref } from 'vue';
import SessionController from '@/actions/App/Http/Controllers/Settings/SessionController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { relativeTime } from '@/lib/time';

type Session = {
    browser: string;
    os: string;
    ip: string | null;
    lastActive: string;
    current: boolean;
};

const props = defineProps<{ sessions: Session[] }>();

const open = ref(false);

const others = computed(
    () => props.sessions.filter((session) => !session.current).length,
);

const onPhone = (session: Session) =>
    session.os === 'Android' || session.os === 'iOS';
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Where you are signed in"
            description="The browsers using your account right now. If one isn't yours, sign out the others and change your password."
        />

        <ul class="divide-y rounded-lg border" data-test="sessions-list">
            <li
                v-for="(session, position) in sessions"
                :key="position"
                class="flex items-center gap-3 px-4 py-3"
            >
                <component
                    :is="onPhone(session) ? Smartphone : Monitor"
                    class="size-5 shrink-0 text-muted-foreground"
                />
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="font-medium">
                            {{ session.browser }} on {{ session.os }}
                        </span>
                        <Badge v-if="session.current" variant="secondary">
                            This device
                        </Badge>
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ session.ip ?? 'Unknown address' }}, active
                        {{ relativeTime(session.lastActive) }}
                    </p>
                </div>
            </li>
        </ul>

        <Dialog v-if="others > 0" v-model:open="open">
            <DialogTrigger as-child>
                <Button variant="outline" data-test="sign-out-others-button">
                    Sign out
                    {{ others === 1 ? 'the other device' : 'other devices' }}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <Form
                    v-bind="SessionController.destroy.form()"
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    class="space-y-6"
                    v-slot="{ errors, processing, reset, clearErrors }"
                    @success="open = false"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>Sign out your other devices?</DialogTitle>
                        <DialogDescription>
                            Every other browser signed in to your account is
                            signed out and has to sign in again. This device
                            stays signed in. Enter your password to confirm.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="sessions-password" class="sr-only">
                            Password
                        </Label>
                        <PasswordInput
                            id="sessions-password"
                            name="current_password"
                            placeholder="Password"
                            autocomplete="current-password"
                        />
                        <InputError :message="errors.current_password" />
                    </div>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button
                                type="button"
                                variant="secondary"
                                @click="
                                    () => {
                                        clearErrors();
                                        reset();
                                    }
                                "
                            >
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" :disabled="processing">
                            <Spinner v-if="processing" />
                            Sign out
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
