import { router } from '@inertiajs/react';
import ExpandMoreOutlined from '@mui/icons-material/ExpandMoreOutlined';
import SearchOutlined from '@mui/icons-material/SearchOutlined';
import Accordion from '@mui/material/Accordion';
import AccordionDetails from '@mui/material/AccordionDetails';
import AccordionSummary from '@mui/material/AccordionSummary';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import InputAdornment from '@mui/material/InputAdornment';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useMemo, useState } from 'react';
import AdminLayout from '../../components/AdminLayout';
import { useCan } from '../../lib/can';

interface Area {
    name: string;
    strings: { default: string; text: string }[];
}

export default function InterfaceText({ areas }: { areas: Area[] }) {
    const can = useCan();
    const locked = !can('settings', 'edit');
    const saved = useMemo(() => Object.fromEntries(areas.flatMap((a) => a.strings.map((s) => [s.default, s.text]))), [areas]);
    const [values, setValues] = useState<Record<string, string>>(saved);
    const [search, setSearch] = useState('');
    const [open, setOpen] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const changed = Object.keys(values).filter((key) => (values[key] ?? '') !== (saved[key] ?? ''));
    const query = search.trim().toLowerCase();
    const shown = areas
        .map((area) => ({ ...area, strings: query === '' ? area.strings : area.strings.filter((s) => s.default.toLowerCase().includes(query) || (values[s.default] ?? '').toLowerCase().includes(query)) }))
        .filter((area) => area.strings.length > 0);

    const save = () => {
        setSaving(true);
        const changes = changed.map((key) => ({ default: key, text: values[key] ?? '' }));
        router.put('/admin/settings/text', { changes }, {
            preserveScroll: true,
            onError: (e) => {
                // Errors come back by position; show each beside its own text.
                const byText: Record<string, string> = {};
                Object.entries(e as Record<string, string>).forEach(([name, message]) => {
                    const index = Number(/^changes\.(\d+)\./.exec(name)?.[1] ?? -1);
                    const change = changes[index];
                    if (change) byText[change.default] = message;
                });
                setErrors(byText);
            },
            onSuccess: () => setErrors({}),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <AdminLayout title="Interface text" actions={!locked && <Button variant="contained" loading={saving} disabled={changed.length === 0} onClick={save}>{changed.length > 0 ? `Save ${changed.length} ${changed.length === 1 ? 'change' : 'changes'}` : 'Save'}</Button>}>
            <Typography sx={{ color: 'text.secondary', mb: 2.5, maxWidth: 760 }}>
                Every button label, message and empty state of the storefront. Write your own wording beside any of them; leave a field empty to keep the original. Words that start with a colon, like :count, are filled in by the store and must stay.
            </Typography>
            <TextField placeholder="Search the text" value={search} onChange={(e) => setSearch(e.target.value)} sx={{ mb: 2.5, width: { xs: '100%', sm: 420 } }}
                slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchOutlined /></InputAdornment> }, htmlInput: { 'aria-label': 'Search the text' } }} />

            <Box sx={{ maxWidth: 980 }}>
                {shown.length === 0 && <Typography sx={{ color: 'text.secondary' }}>No text matches your search.</Typography>}
                {shown.map((area) => {
                    const count = area.strings.filter((s) => (values[s.default] ?? '') !== '').length;
                    const expanded = query !== '' || open === area.name;
                    return (
                        <Accordion key={area.name} expanded={expanded} onChange={(_, isOpen) => setOpen(isOpen ? area.name : null)} disableGutters slotProps={{ transition: { unmountOnExit: true } }}>
                            <AccordionSummary expandIcon={<ExpandMoreOutlined />}>
                                <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, flexWrap: 'wrap' }}>
                                    <Typography sx={{ fontWeight: 600 }}>{area.name}</Typography>
                                    <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>{area.strings.length} {area.strings.length === 1 ? 'text' : 'texts'}</Typography>
                                    {count > 0 && <Chip size="small" color="primary" label={`${count} reworded`} />}
                                </Box>
                            </AccordionSummary>
                            <AccordionDetails>
                                <Stack spacing={2}>
                                    {area.strings.map((s) => (
                                        <Box key={s.default} sx={{ display: 'grid', gap: { xs: 0.75, md: 2 }, gridTemplateColumns: { xs: 'minmax(0, 1fr)', md: 'minmax(0, 1fr) minmax(0, 1fr)' }, alignItems: 'start' }}>
                                            <Typography sx={{ pt: { md: 1 }, wordBreak: 'break-word' }}>{s.default}</Typography>
                                            <TextField size="small" multiline maxRows={4} disabled={locked} placeholder={s.default} value={values[s.default] ?? ''} onChange={(e) => setValues({ ...values, [s.default]: e.target.value })}
                                                error={!!errors[s.default]} helperText={errors[s.default]} slotProps={{ htmlInput: { 'aria-label': `Your wording for: ${s.default}`, maxLength: 500 } }} />
                                        </Box>
                                    ))}
                                </Stack>
                            </AccordionDetails>
                        </Accordion>
                    );
                })}
            </Box>
        </AdminLayout>
    );
}
