import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/admin/settings';

type Setting = {
    key: string;
    group: string;
    label: string | null;
    description: string | null;
    value: string;
};

type Props = {
    settings: Setting[];
};

export default function AdminSettings({ settings }: Props) {
    return (
        <>
            <Head title="Integration settings" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">
                        Integration settings
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        App-level API keys shared across every user. These never
                        appear in the mobile app&apos;s per-account config
                        screen.
                    </p>
                </div>

                {settings.length === 0 ? (
                    <Card>
                        <CardContent className="text-muted-foreground pt-6 text-sm">
                            No admin-only settings registered yet.
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4">
                        {settings.map((setting) => (
                            <SettingCard key={setting.key} setting={setting} />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

function SettingCard({ setting }: { setting: Setting }) {
    const isSecret =
        setting.key.toLowerCase().includes('key') ||
        setting.key.toLowerCase().includes('secret');

    return (
        <Card>
            <CardHeader>
                <CardTitle>{setting.label ?? setting.key}</CardTitle>
                {setting.description && (
                    <CardDescription>{setting.description}</CardDescription>
                )}
            </CardHeader>
            <CardContent>
                <Form
                    {...update.form()}
                    resetOnSuccess={false}
                    className="flex flex-col gap-2"
                >
                    {({ processing, errors, recentlySuccessful }) => (
                        <>
                            <input
                                type="hidden"
                                name="key"
                                value={setting.key}
                            />
                            <div className="flex items-end gap-3">
                                <div className="grid flex-1 gap-2">
                                    <Label htmlFor={`setting-${setting.key}`}>
                                        {setting.key}
                                    </Label>
                                    <Input
                                        id={`setting-${setting.key}`}
                                        name="value"
                                        type={isSecret ? 'password' : 'text'}
                                        defaultValue={setting.value}
                                        placeholder={
                                            setting.value === ''
                                                ? 'Not set'
                                                : undefined
                                        }
                                        autoComplete="off"
                                    />
                                </div>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Save
                                </Button>
                            </div>
                            <InputError message={errors.key ?? errors.value} />
                            {recentlySuccessful && (
                                <p className="text-sm text-green-600">Saved.</p>
                            )}
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}
