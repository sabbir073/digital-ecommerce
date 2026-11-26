import { router } from '@inertiajs/react';
import CheckCircleOutlined from '@mui/icons-material/CheckCircleOutlined';
import ErrorOutlineOutlined from '@mui/icons-material/ErrorOutlineOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import ButtonBase from '@mui/material/ButtonBase';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import AdminLayout from '../../components/AdminLayout';
import RouterLink from '../../components/RouterLink';

interface Item {
    type: string;
    typeLabel: string;
    id: number;
    name: string;
    public: boolean;
    problems: { label: string; hint: string }[];
}
interface Count {
    type: string;
    label: string;
    total: number;
    failing: number;
}

export const seoUrl = (type: string, id: number) => (type === 'home' ? '/admin/seo/home' : `/admin/seo/${type}/${id}`);

export default function SeoDashboard({ items, counts, filter }: { items: Item[]; counts: Count[]; filter: string }) {
    const failing = counts.reduce((sum, c) => sum + c.failing, 0);
    const total = counts.reduce((sum, c) => sum + c.total, 0);
    const show = (type: string) => router.get('/admin/seo', type ? { type } : {}, { preserveScroll: true, preserveState: true });

    return (
        <AdminLayout title="SEO">
            <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', mb: 3 }}>
                <Typography sx={{ color: 'text.secondary', maxWidth: 640 }}>
                    {failing === 0
                        ? `All ${total} pages pass their SEO checks.`
                        : `${failing} of ${total} pages have something to fix. Open one to see what is wrong and fix it.`}
                </Typography>
                <Stack direction="row" spacing={1.5} useFlexGap sx={{ flexWrap: 'wrap' }}>
                    <Button component={RouterLink} href="/admin/seo/settings" variant="outlined">SEO settings</Button>
                    <Button component={RouterLink} href="/admin/seo/redirects" variant="outlined">Redirects and 404s</Button>
                </Stack>
            </Box>

            <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr 1fr', md: 'repeat(3, 1fr)', lg: 'repeat(6, 1fr)' }, mb: 3 }}>
                {counts.map((count) => {
                    const active = filter === count.type;
                    return (
                        <Card key={count.type} sx={{ borderColor: active ? 'primary.main' : undefined, borderWidth: active ? 2 : undefined }}>
                            <ButtonBase onClick={() => show(active ? '' : count.type)} aria-pressed={active} sx={{ display: 'block', width: '100%', textAlign: 'left', p: 2 }}>
                                <Typography sx={{ fontSize: 13, color: 'text.secondary' }}>{count.label}</Typography>
                                <Typography sx={{ fontSize: 24, fontWeight: 700, lineHeight: 1.3, color: count.failing ? 'warning.dark' : 'success.dark' }}>
                                    {count.failing}
                                </Typography>
                                <Typography sx={{ fontSize: 13, color: 'text.secondary' }}>to fix, of {count.total}</Typography>
                            </ButtonBase>
                        </Card>
                    );
                })}
            </Box>

            {items.length === 0 ? (
                <Card sx={{ p: 5, textAlign: 'center' }}>
                    <CheckCircleOutlined color="success" sx={{ fontSize: 40 }} />
                    <Typography variant="h4" component="h2" sx={{ mt: 1 }}>Nothing to fix</Typography>
                    <Typography sx={{ color: 'text.secondary', mt: 0.5 }}>{filter ? 'Every page of this kind passes its checks.' : 'Every page passes its checks.'}</Typography>
                    {filter && <Button sx={{ mt: 2 }} onClick={() => show('')}>Show all kinds</Button>}
                </Card>
            ) : (
                <Stack spacing={1.5}>
                    {items.map((item) => (
                        <Card key={`${item.type}-${item.id}`} sx={{ p: 2.5, display: 'flex', gap: 2, flexWrap: 'wrap', alignItems: 'flex-start', justifyContent: 'space-between' }}>
                            <Box sx={{ minWidth: 0, flex: '1 1 320px' }}>
                                <Box sx={{ display: 'flex', gap: 1, alignItems: 'center', flexWrap: 'wrap', mb: 1 }}>
                                    <Typography sx={{ fontWeight: 600, wordBreak: 'break-word' }}>{item.name}</Typography>
                                    <Chip size="small" label={item.typeLabel} />
                                    {!item.public && <Chip size="small" variant="outlined" label="Not published" />}
                                </Box>
                                <Stack spacing={0.75}>
                                    {item.problems.map((problem) => (
                                        <Box key={problem.label} sx={{ display: 'flex', gap: 1, alignItems: 'flex-start' }}>
                                            <ErrorOutlineOutlined color="warning" sx={{ fontSize: 18, mt: '2px', flexShrink: 0 }} />
                                            <Typography sx={{ fontSize: 14 }}><strong>{problem.label}.</strong> {problem.hint}</Typography>
                                        </Box>
                                    ))}
                                </Stack>
                            </Box>
                            <Button component={RouterLink} href={seoUrl(item.type, item.id)} variant="outlined" sx={{ flexShrink: 0 }}>Fix</Button>
                        </Card>
                    ))}
                </Stack>
            )}
        </AdminLayout>
    );
}
