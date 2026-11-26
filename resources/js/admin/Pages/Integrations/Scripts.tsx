import { useForm } from '@inertiajs/react';
import AddOutlined from '@mui/icons-material/AddOutlined';
import ArrowBackOutlined from '@mui/icons-material/ArrowBackOutlined';
import DeleteOutlined from '@mui/icons-material/DeleteOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import type { FormEvent } from 'react';
import AdminLayout from '../../components/AdminLayout';
import RouterLink from '../../components/RouterLink';

interface Script {
    id: string;
    name: string;
    placement: string;
    scope: 'all' | 'paths';
    paths: string[];
    code: string;
    enabled: boolean;
}
interface Props {
    scripts: Script[];
    placements: Record<string, string>;
    max: number;
    canEdit: boolean;
}

const mono = { fontFamily: 'ui-monospace, Menlo, monospace', fontSize: 14, lineHeight: 1.6 };

export default function CustomScripts({ scripts, placements, max, canEdit }: Props) {
    const form = useForm<{ scripts: Script[] }>({ scripts });
    const errors = form.errors as Record<string, string>;
    const list = form.data.scripts;
    const patch = (index: number, values: Partial<Script>) => form.setData('scripts', list.map((script, i) => (i === index ? { ...script, ...values } : script)));
    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put('/admin/integrations/scripts', { preserveScroll: true });
    };

    return (
        <AdminLayout title="Custom scripts">
            <Box sx={{ mb: 3 }}>
                <Button component={RouterLink} href="/admin/integrations" startIcon={<ArrowBackOutlined />} color="inherit">Integrations</Button>
            </Box>

            <Box component="form" onSubmit={save} noValidate sx={{ maxWidth: 960 }}>
                <Typography sx={{ color: 'text.secondary', mb: 2 }}>
                    For any service that has no card under Integrations. The code is added to the storefront exactly as written, on every page or only on the addresses you list. It is never added inside the admin area or the page builder.
                </Typography>
                <Alert severity={canEdit ? 'warning' : 'info'} sx={{ mb: 3 }}>
                    {canEdit
                        ? 'This code runs for visitors and is not held back by the cookie banner. Paste only code from a service you trust, and ask for consent yourself where the law requires it.'
                        : 'Only a Super Admin can change custom scripts, because the code runs for every visitor.'}
                </Alert>

                <Stack spacing={3}>
                    {list.length === 0 && (
                        <Card sx={{ p: 5, textAlign: 'center' }}>
                            <Typography variant="h4" component="h2">No custom scripts</Typography>
                            <Typography sx={{ color: 'text.secondary', mt: 0.5 }}>Add one when a service gives you a snippet to paste into your site.</Typography>
                        </Card>
                    )}
                    {list.map((script, index) => (
                        <Card key={script.id || `new-${index}`} sx={{ p: 3 }}>
                            <Stack spacing={2.5}>
                                <Box sx={{ display: 'flex', gap: 2, alignItems: 'flex-start' }}>
                                    <TextField label="Name" required fullWidth disabled={!canEdit} value={script.name} onChange={(e) => patch(index, { name: e.target.value })} error={!!errors[`scripts.${index}.name`]} helperText={errors[`scripts.${index}.name`] ?? 'Only you see this, for example "Hotjar".'} slotProps={{ htmlInput: { maxLength: 80 } }} />
                                    {canEdit && <IconButton aria-label={`Remove ${script.name || 'this script'}`} onClick={() => form.setData('scripts', list.filter((_, i) => i !== index))} sx={{ mt: 1 }}><DeleteOutlined /></IconButton>}
                                </Box>
                                <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' } }}>
                                    <TextField select label="Where in the page" disabled={!canEdit} value={script.placement} onChange={(e) => patch(index, { placement: e.target.value })}>
                                        {Object.entries(placements).map(([key, label]) => <MenuItem key={key} value={key}>{label}</MenuItem>)}
                                    </TextField>
                                    <TextField select label="On which pages" disabled={!canEdit} value={script.scope} onChange={(e) => patch(index, { scope: e.target.value as Script['scope'] })}>
                                        <MenuItem value="all">Every page</MenuItem>
                                        <MenuItem value="paths">Only the addresses I list</MenuItem>
                                    </TextField>
                                </Box>
                                {script.scope === 'paths' && (
                                    <TextField label="Addresses, one per line" multiline minRows={2} disabled={!canEdit} value={script.paths.join('\n')} onChange={(e) => patch(index, { paths: e.target.value.split('\n') })}
                                        error={!!errors[`scripts.${index}.paths`] || Object.keys(errors).some((key) => key.startsWith(`scripts.${index}.paths.`))}
                                        helperText={errors[`scripts.${index}.paths`] ?? Object.entries(errors).find(([key]) => key.startsWith(`scripts.${index}.paths.`))?.[1] ?? 'The part after the site name, like /p/summer-sale. End with /* for everything under an address, like /blog/*.'}
                                        slotProps={{ htmlInput: { spellCheck: false, style: mono } }} />
                                )}
                                <TextField label="Code" required multiline minRows={4} maxRows={20} disabled={!canEdit} value={script.code} onChange={(e) => patch(index, { code: e.target.value })} error={!!errors[`scripts.${index}.code`]} helperText={errors[`scripts.${index}.code`]}
                                    slotProps={{ htmlInput: { maxLength: 20000, spellCheck: false, style: mono } }} />
                                <FormControlLabel label={script.enabled ? 'Switched on' : 'Switched off'} control={<Switch checked={script.enabled} disabled={!canEdit} onChange={(e) => patch(index, { enabled: e.target.checked })} />} />
                            </Stack>
                        </Card>
                    ))}
                </Stack>

                {canEdit && (
                    <Box sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', mt: 3 }}>
                        <Button variant="outlined" startIcon={<AddOutlined />} disabled={list.length >= max} onClick={() => form.setData('scripts', [...list, { id: '', name: '', placement: 'head', scope: 'all', paths: [], code: '', enabled: true }])}>Add a script</Button>
                        <Button type="submit" variant="contained" loading={form.processing} disabled={!form.isDirty}>Save custom scripts</Button>
                    </Box>
                )}
            </Box>
        </AdminLayout>
    );
}
