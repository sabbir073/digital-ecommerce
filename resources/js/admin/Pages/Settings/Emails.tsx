import { router } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useEffect, useRef, useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import ConfirmDialog from '../../components/ConfirmDialog';
import { useCan } from '../../lib/can';
import { sendJson } from '../../lib/http';

interface Template {
    key: string;
    name: string;
    when: string;
    subject: string;
    body: string;
    custom: boolean;
    variables: Record<string, string>;
    blocks: Record<string, string>;
    required: string[];
    defaults: { subject: string; body: string };
}

export default function EmailTemplates({ templates }: { templates: Template[] }) {
    const can = useCan();
    const locked = !can('settings', 'edit');
    const [key, setKey] = useState(templates[0]?.key ?? '');
    const template = templates.find((t) => t.key === key) as Template;
    const [subject, setSubject] = useState(template.subject);
    const [body, setBody] = useState(template.body);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [preview, setPreview] = useState<{ subject: string; html: string } | null>(null);
    const [previewError, setPreviewError] = useState('');
    const [confirmReset, setConfirmReset] = useState(false);
    const bodyRef = useRef<HTMLTextAreaElement | null>(null);
    const dirty = subject !== template.subject || body !== template.body;

    // Opening another email, or saving this one, loads its saved text into the editor.
    useEffect(() => {
        setSubject(template.subject);
        setBody(template.body);
        setErrors({});
    }, [template.key, template.subject, template.body]);

    // The preview follows the editor a moment after typing stops.
    useEffect(() => {
        const timer = window.setTimeout(async () => {
            try {
                const result = await sendJson<{ subject?: string; html?: string; error?: string }>('POST', `/admin/settings/emails/${key}/preview`, { subject, body });
                if (result.error) {
                    setPreviewError(result.error);
                } else {
                    setPreview({ subject: result.subject ?? '', html: result.html ?? '' });
                    setPreviewError('');
                }
            } catch (error) {
                setPreviewError((error as Error).message);
            }
        }, 500);
        return () => window.clearTimeout(timer);
    }, [key, subject, body]);

    const insert = (name: string) => {
        const area = bodyRef.current;
        const token = name === 'button' ? '{{button:Label}}' : `{{${name}}}`;
        if (!area) return setBody(body + token);
        const start = area.selectionStart ?? body.length;
        const end = area.selectionEnd ?? body.length;
        setBody(body.slice(0, start) + token + body.slice(end));
        window.requestAnimationFrame(() => {
            area.focus();
            area.setSelectionRange(start + token.length, start + token.length);
        });
    };

    const save = () => {
        setSaving(true);
        router.put(`/admin/settings/emails/${key}`, { subject, body }, {
            preserveScroll: true,
            onError: (e) => setErrors(e as Record<string, string>),
            onSuccess: () => setErrors({}),
            onFinish: () => setSaving(false),
        });
    };

    const chips = (items: Record<string, string>, color: 'default' | 'primary') => Object.entries(items).map(([name, text]) => (
        <Box key={name} sx={{ display: 'flex', gap: 1.5, alignItems: 'baseline' }}>
            <Chip label={`{{${name}}}`} size="small" color={color} variant="outlined" disabled={locked} onClick={() => insert(name)} sx={{ fontFamily: 'monospace', flexShrink: 0 }} />
            <Typography sx={{ fontSize: 13, color: 'text.secondary' }}>{text}{template.required.includes(name) ? ' Must stay in the email.' : ''}</Typography>
        </Box>
    ));

    return (
        <AdminLayout title="Emails">
            <Typography sx={{ color: 'text.secondary', mb: 2 }}>The subject and text of every email a customer gets. Words in double braces are filled in when the email is sent.</Typography>
            <Tabs value={key} onChange={(_, value: string) => setKey(value)} variant="scrollable" scrollButtons="auto" sx={{ mb: 3, borderBottom: 1, borderColor: 'divider' }}>
                {templates.map((t) => <Tab key={t.key} value={t.key} label={t.name} />)}
            </Tabs>

            <Box sx={{ display: 'grid', gap: 3, gridTemplateColumns: { xs: 'minmax(0, 1fr)', lg: 'minmax(0, 1fr) minmax(0, 1fr)' }, alignItems: 'start' }}>
                <Card sx={{ p: 3, minWidth: 0 }}>
                    <Typography sx={{ color: 'text.secondary', mb: 2.5 }}>{template.when}</Typography>
                    <Stack spacing={2.5}>
                        <TextField label="Subject" disabled={locked} value={subject} onChange={(e) => setSubject(e.target.value)} error={!!errors.subject} helperText={errors.subject} slotProps={{ htmlInput: { maxLength: 150 } }} />
                        <TextField label="Email text" multiline minRows={14} disabled={locked} value={body} onChange={(e) => setBody(e.target.value)} inputRef={bodyRef} error={!!errors.body}
                            helperText={errors.body ?? 'Start a line with # for a heading. Put **two stars** around words to make them bold.'} slotProps={{ htmlInput: { maxLength: 10000, style: { fontFamily: 'ui-monospace, Menlo, monospace', fontSize: 14, lineHeight: 1.6 } } }} />
                    </Stack>

                    <Typography sx={{ fontWeight: 600, mt: 3, mb: 1 }}>Click to add at the cursor</Typography>
                    <Stack spacing={1}>
                        {chips(template.variables, 'default')}
                        {chips(template.blocks, 'primary')}
                    </Stack>

                    {!locked && (
                        <Box sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', mt: 3 }}>
                            <Button variant="contained" loading={saving} disabled={!dirty} onClick={save}>Save email</Button>
                            {dirty && <Button color="inherit" onClick={() => { setSubject(template.subject); setBody(template.body); setErrors({}); }}>Undo changes</Button>}
                            {template.custom && <Button color="inherit" onClick={() => setConfirmReset(true)}>Reset to original</Button>}
                        </Box>
                    )}
                </Card>

                <Card sx={{ p: 3, minWidth: 0 }}>
                    <Typography variant="h4" component="h2">Preview</Typography>
                    <Typography sx={{ color: 'text.secondary', fontSize: 13, mt: 0.5, mb: 2 }}>Made with a sample order. Links in the preview do not work.</Typography>
                    {previewError && <Alert severity="warning" sx={{ mb: 2 }}>{previewError}</Alert>}
                    {preview && (
                        <>
                            <Typography sx={{ fontSize: 13, color: 'text.secondary' }}>Subject</Typography>
                            <Typography sx={{ fontWeight: 600, mb: 2, wordBreak: 'break-word' }}>{preview.subject}</Typography>
                            <Box component="iframe" title="Email preview" sandbox="" srcDoc={preview.html} sx={{ width: '100%', height: 640, border: 1, borderColor: 'divider', borderRadius: 2, bgcolor: '#fff' }} />
                        </>
                    )}
                </Card>
            </Box>

            <ConfirmDialog open={confirmReset} title="Reset this email to the original text?" text="Your changes to this email are removed." confirmLabel="Reset" onClose={() => setConfirmReset(false)}
                onConfirm={() => router.delete(`/admin/settings/emails/${key}`, { preserveScroll: true, onFinish: () => setConfirmReset(false) })} />
        </AdminLayout>
    );
}
