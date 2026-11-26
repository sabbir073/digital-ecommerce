import { usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Divider from '@mui/material/Divider';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import AdminLayout from '../components/AdminLayout';
import { actionLabel, formatDateTime } from '../lib/format';
import type { SharedProps } from '../lib/types';
import RouterLink from '../components/RouterLink';

interface Props {
    team: { active: number; invited: number; suspended: number; roles: number; withTwoFactor: number } | null;
    recentActivity: { id: number; actor: string; action: string; module: string; label: string | null; at: string | null }[] | null;
}

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <Card sx={{ flex: '1 1 160px' }}>
            <CardContent>
                <Typography sx={{ color: 'text.secondary', fontSize: 13, fontWeight: 600 }}>{label}</Typography>
                <Typography variant="h1" component="p" sx={{ mt: 0.5 }}>{value}</Typography>
            </CardContent>
        </Card>
    );
}

export default function Dashboard({ team, recentActivity }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const firstName = auth.user?.name.split(' ')[0] ?? '';

    return (
        <AdminLayout title={`Hello, ${firstName}`}>
            <Stack spacing={3}>
                {!auth.user?.hasTwoFactor && (
                    <Alert severity="info" action={<Button component={RouterLink} href="/admin/account" color="inherit" size="small">Set up</Button>}>
                        Two-factor sign-in is off for your account. Turn it on to protect the store.
                    </Alert>
                )}

                {team && (
                    <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 2 }}>
                        <Stat label="Active staff" value={team.active} />
                        <Stat label="Pending invitations" value={team.invited} />
                        <Stat label="Suspended" value={team.suspended} />
                        <Stat label="Roles" value={team.roles} />
                        <Stat label="Using two-factor" value={team.withTwoFactor} />
                    </Box>
                )}

                {recentActivity && (
                    <Card>
                        <CardContent sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
                            <Typography variant="h3" component="h2" sx={{ flex: 1 }}>Recent activity</Typography>
                            <Button component={RouterLink} href="/admin/activity" variant="outlined" size="small">View all</Button>
                        </CardContent>
                        <Divider />
                        {recentActivity.length === 0 ? (
                            <CardContent><Typography sx={{ color: 'text.secondary' }}>Nothing has happened yet.</Typography></CardContent>
                        ) : (
                            recentActivity.map((entry, index) => (
                                <Box key={entry.id}>
                                    {index > 0 && <Divider />}
                                    <Box sx={{ px: 2, py: 1.5, display: 'flex', flexWrap: 'wrap', gap: 1, alignItems: 'baseline' }}>
                                        <Typography sx={{ flex: 1, minWidth: 220 }}>
                                            <strong>{entry.actor}</strong> · {actionLabel(entry.action)}
                                            {entry.label && entry.label !== entry.actor ? ` ${entry.label}` : ''}
                                        </Typography>
                                        <Typography sx={{ color: 'text.secondary', fontSize: 13 }}>{formatDateTime(entry.at)}</Typography>
                                    </Box>
                                </Box>
                            ))
                        )}
                    </Card>
                )}

                {!team && !recentActivity && (
                    <Card>
                        <CardContent>
                            <Typography sx={{ color: 'text.secondary' }}>
                                You are signed in as {auth.user?.role}. Use the menu to open the areas your role includes.
                            </Typography>
                        </CardContent>
                    </Card>
                )}
            </Stack>
        </AdminLayout>
    );
}
