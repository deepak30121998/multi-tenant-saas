<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'verified']);
    }

    /**
     * Display the tenant dashboard.
     */
    public function index()
    {
        $user = Auth::user();
        $tenant = tenant();

        $stats = $this->getDashboardStats();
        $recentActivity = $this->getRecentActivity();
        $chartData = $this->getChartData();
        $notifications = $this->getUnreadNotifications();

        return view('tenant.dashboard', compact(
            'user',
            'tenant',
            'stats',
            'recentActivity',
            'chartData',
            'notifications'
        ));
    }

    /**
     * Get dashboard statistics.
     */
    protected function getDashboardStats()
    {
        $tenant = tenant();
        $user = Auth::user();

        $stats = [
            'total_users' => User::count(),
            'active_users' => User::where('status', 'active')->count(),
            'new_users_this_month' => User::whereMonth('created_at', now()->month)->count(),
            'storage_used' => $this->formatBytes($tenant->storage_used),
            'storage_limit' => $this->formatBytes($tenant->limits['storage'] ?? 0),
            'storage_percentage' => $this->calculateStoragePercentage($tenant),
        ];

        // Add role-specific stats
        if ($user->hasRole(['admin', 'manager'])) {
            $stats['pending_invitations'] = $this->getPendingInvitations();
            $stats['recent_logins'] = User::whereDate('last_login_at', today())->count();
        }

        // Add team stats if teams are enabled
        if ($tenant->features['teams'] ?? false) {
            $stats['total_teams'] = $this->getTeamCount();
            $stats['user_teams'] = $user->teams()->count();
        }

        return $stats;
    }

    /**
     * Get recent activity for the dashboard.
     */
    protected function getRecentActivity()
    {
        return activity()
            ->latest()
            ->take(10)
            ->get()
            ->map(function ($activity) {
                return [
                    'id' => $activity->id,
                    'description' => $activity->description,
                    'causer' => $activity->causer ? $activity->causer->name : 'System',
                    'created_at' => $activity->created_at->diffForHumans(),
                    'properties' => $activity->properties,
                ];
            });
    }

    /**
     * Get chart data for dashboard.
     */
    protected function getChartData()
    {
        $days = 30;
        $dates = collect();
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $dates->push(Carbon::now()->subDays($i)->format('Y-m-d'));
        }

        // User registrations over time
        $userRegistrations = User::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', Carbon::now()->subDays($days))
            ->groupBy('date')
            ->pluck('count', 'date');

        // User logins over time
        $userLogins = User::selectRaw('DATE(last_login_at) as date, COUNT(*) as count')
            ->whereNotNull('last_login_at')
            ->where('last_login_at', '>=', Carbon::now()->subDays($days))
            ->groupBy('date')
            ->pluck('count', 'date');

        $chartData = [
            'labels' => $dates->map(fn($date) => Carbon::parse($date)->format('M d'))->toArray(),
            'registrations' => $dates->map(fn($date) => $userRegistrations->get($date, 0))->toArray(),
            'logins' => $dates->map(fn($date) => $userLogins->get($date, 0))->toArray(),
        ];

        return $chartData;
    }

    /**
     * Get unread notifications.
     */
    protected function getUnreadNotifications()
    {
        return Auth::user()->unreadNotifications()
            ->take(5)
            ->get()
            ->map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'type' => class_basename($notification->type),
                    'data' => $notification->data,
                    'created_at' => $notification->created_at->diffForHumans(),
                ];
            });
    }

    /**
     * API endpoint for dashboard stats.
     */
    public function stats(Request $request)
    {
        $stats = $this->getDashboardStats();
        
        return response()->json([
            'stats' => $stats,
            'tenant' => [
                'name' => tenant()->name,
                'plan' => tenant()->plan,
                'status' => tenant()->status,
            ]
        ]);
    }

    /**
     * API endpoint for chart data.
     */
    public function chartData(Request $request)
    {
        $type = $request->get('type', 'users');
        $period = $request->get('period', 30);

        switch ($type) {
            case 'users':
                return response()->json($this->getUsersChartData($period));
            case 'activity':
                return response()->json($this->getActivityChartData($period));
            case 'storage':
                return response()->json($this->getStorageChartData($period));
            default:
                return response()->json(['error' => 'Invalid chart type'], 400);
        }
    }

    /**
     * API endpoint for recent activity.
     */
    public function recentActivity(Request $request)
    {
        $limit = $request->get('limit', 10);
        
        $activity = activity()
            ->latest()
            ->take($limit)
            ->get();

        return response()->json([
            'activity' => $activity->map(function ($item) {
                return [
                    'id' => $item->id,
                    'description' => $item->description,
                    'causer' => $item->causer ? $item->causer->name : 'System',
                    'created_at' => $item->created_at,
                    'created_at_human' => $item->created_at->diffForHumans(),
                    'properties' => $item->properties,
                ];
            })
        ]);
    }

    /**
     * API endpoint for notifications.
     */
    public function notifications(Request $request)
    {
        $user = Auth::user();
        $unread = $request->get('unread_only', false);

        $query = $unread ? $user->unreadNotifications() : $user->notifications();
        
        $notifications = $query->take(20)->get();

        return response()->json([
            'notifications' => $notifications->map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'type' => class_basename($notification->type),
                    'data' => $notification->data,
                    'read_at' => $notification->read_at,
                    'created_at' => $notification->created_at,
                    'created_at_human' => $notification->created_at->diffForHumans(),
                ];
            }),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark notification as read.
     */
    public function markNotificationRead(Request $request, $id)
    {
        $notification = Auth::user()->notifications()->find($id);

        if (!$notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllNotificationsRead(Request $request)
    {
        Auth::user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true]);
    }

    /**
     * Get users chart data.
     */
    protected function getUsersChartData($days)
    {
        $dates = collect();
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $dates->push(Carbon::now()->subDays($i)->format('Y-m-d'));
        }

        $registrations = User::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', Carbon::now()->subDays($days))
            ->groupBy('date')
            ->pluck('count', 'date');

        $activeUsers = User::selectRaw('DATE(last_login_at) as date, COUNT(DISTINCT id) as count')
            ->whereNotNull('last_login_at')
            ->where('last_login_at', '>=', Carbon::now()->subDays($days))
            ->groupBy('date')
            ->pluck('count', 'date');

        return [
            'labels' => $dates->map(fn($date) => Carbon::parse($date)->format('M d'))->toArray(),
            'datasets' => [
                [
                    'label' => 'New Users',
                    'data' => $dates->map(fn($date) => $registrations->get($date, 0))->toArray(),
                    'borderColor' => 'rgb(59, 130, 246)',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                ],
                [
                    'label' => 'Active Users',
                    'data' => $dates->map(fn($date) => $activeUsers->get($date, 0))->toArray(),
                    'borderColor' => 'rgb(16, 185, 129)',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                ]
            ]
        ];
    }

    /**
     * Get activity chart data.
     */
    protected function getActivityChartData($days)
    {
        $dates = collect();
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $dates->push(Carbon::now()->subDays($i)->format('Y-m-d'));
        }

        $activities = DB::table('activity_log')
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', Carbon::now()->subDays($days))
            ->groupBy('date')
            ->pluck('count', 'date');

        return [
            'labels' => $dates->map(fn($date) => Carbon::parse($date)->format('M d'))->toArray(),
            'datasets' => [
                [
                    'label' => 'Activities',
                    'data' => $dates->map(fn($date) => $activities->get($date, 0))->toArray(),
                    'borderColor' => 'rgb(245, 158, 11)',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                ]
            ]
        ];
    }

    /**
     * Get storage chart data.
     */
    protected function getStorageChartData($days)
    {
        $tenant = tenant();
        
        // This is a simplified example - in reality you'd track storage over time
        $storageUsed = $tenant->storage_used;
        $storageLimit = $tenant->limits['storage'] ?? 0;

        return [
            'labels' => ['Used', 'Available'],
            'datasets' => [
                [
                    'data' => [$storageUsed, max(0, $storageLimit - $storageUsed)],
                    'backgroundColor' => ['rgb(239, 68, 68)', 'rgb(229, 231, 235)'],
                ]
            ]
        ];
    }

    /**
     * Helper methods.
     */
    protected function formatBytes($bytes)
    {
        if ($bytes == 0) return '0 B';
        if ($bytes == -1) return 'Unlimited';

        $k = 1024;
        $sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes) / log($k));

        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }

    protected function calculateStoragePercentage($tenant)
    {
        $used = $tenant->storage_used;
        $limit = $tenant->limits['storage'] ?? 0;

        if ($limit <= 0) return 0; // Unlimited or no limit
        
        return min(100, round(($used / $limit) * 100, 2));
    }

    protected function getPendingInvitations()
    {
        // This would depend on your invitation system implementation
        return 0; // Placeholder
    }

    protected function getTeamCount()
    {
        // This would depend on your team model implementation
        return DB::table('teams')->count() ?? 0;
    }
}