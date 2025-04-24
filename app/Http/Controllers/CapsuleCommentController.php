<?php

namespace App\Http\Controllers;

use App\Models\CapsuleComment;
use App\Models\Capsule;
use App\Models\User;
use App\Models\Friendship;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CapsuleCommentController extends Controller
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'capsule_id' => 'required|exists:capsules,id',
            'user_id' => 'required|integer',
            'comment' => 'required|string|max:1000',
        ]);

        $user = Auth::user();

        if ($user->id != $validated['user_id']) {
            return response()->json(['message' => 'Unauthorized user ID'], 403);
        }

        $capsule = Capsule::findOrFail($validated['capsule_id']);

        $comment = new CapsuleComment();
        $comment->capsule_id = $validated['capsule_id'];
        $comment->user_id = $user->id;
        $comment->comment = $validated['comment'];
        $comment->save();

        return response()->json([
            'message' => 'Comment added successfully',
            'comment' => $comment
        ], 201);
    }

    /**
     * @param  int  $capsuleId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getComments($capsuleId)
    {
        $capsule = Capsule::findOrFail($capsuleId);

        $comments = CapsuleComment::where('capsule_id', $capsuleId)
            ->with(['user:id,name,profile_image'])
            ->orderBy('created_at', 'desc')
            ->get();

        $formattedComments = $comments->map(function($comment) {
            return [
                'id' => $comment->id,
                'content' => $comment->comment,
                'created_at' => $comment->created_at,
                'user' => [
                    'id' => $comment->user->id,
                    'name' => $comment->user->name,
                    'profile_image_url' => $comment->user->profile_image ? asset('storage/' . $comment->user->profile_image) : null
                ]
            ];
        });

        return response()->json([
            'comments' => $formattedComments,
            'count' => $comments->count()
        ]);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCommentGeneralInfo()
    {
        $user = Auth::user();

        $query = CapsuleComment::with(['user:id,name,profile_image', 'capsule:id,title,privacy,user_id'])
            ->latest()
            ->select('id', 'capsule_id', 'user_id', 'comment', 'created_at');

        if ($user) {
            $commentActivities = $query
                ->where(function($q) use ($user) {
                    $q->whereHas('capsule', function($query) {
                        $query->where('privacy', 'public');
                    });

                    $q->orWhereHas('capsule', function($query) use ($user) {
                        $query->where('user_id', $user->id);
                    });

                    $q->orWhere(function($query) use ($user) {
                        $friendIds = Friendship::where(function($q) use ($user) {
                            $q->where('user_id', $user->id)
                                ->where('status', 'accepted');
                        })->orWhere(function($q) use ($user) {
                            $q->where('friend_id', $user->id)
                                ->where('status', 'accepted');
                        })
                            ->get()
                            ->map(function($friendship) use ($user) {
                                return $friendship->user_id == $user->id
                                    ? $friendship->friend_id
                                    : $friendship->user_id;
                            });

                        $query->whereHas('capsule', function($q) use ($friendIds) {
                            $q->where('privacy', 'friends')
                                ->whereIn('user_id', $friendIds);
                        });
                    });

                    $q->orWhereHas('capsule', function($query) use ($user) {
                        $query->whereHas('capsuleUsers', function($q) use ($user) {
                            $q->where('users.id', $user->id)
                                ->where('capsule_user.status', 'accepted');
                        });
                    });
                })
                ->limit(30)
                ->get();
        } else {
            $commentActivities = $query
                ->whereHas('capsule', function($q) {
                    $q->where('privacy', 'public');
                })
                ->limit(20)
                ->get();
        }

        $formattedActivities = $commentActivities->map(function($comment) use ($user) {
            $timeElapsed = $this->getTimeElapsed($comment->created_at);
            $isCurrentUser = $user && $comment->user_id === $user->id;

            return [
                'id' => $comment->id,
                'type' => 'comment',
                'user' => [
                    'id' => $comment->user->id,
                    'name' => $comment->user->name,
                    'profile_image' => $comment->user->profile_image ? asset('storage/' . $comment->user->profile_image) : null,
                    'initials' => $this->getInitials($comment->user->name)
                ],
                'action' => 'commented on',
                'capsule' => [
                    'id' => $comment->capsule->id,
                    'title' => $comment->capsule->title
                ],
                'content_preview' => substr($comment->comment, 0, 50) . (strlen($comment->comment) > 50 ? '...' : ''),
                'time_elapsed' => $timeElapsed,
                'created_at' => $comment->created_at->format('Y-m-d H:i:s'),
                'is_current_user' => $isCurrentUser
            ];
        });

        return response()->json([
            'activities' => $formattedActivities
        ]);
    }

    /**
     * @param \Carbon\Carbon $datetime
     * @return string
     */
    private function getTimeElapsed($datetime)
    {
        if (!($datetime instanceof \Carbon\Carbon)) {
            $datetime = \Carbon\Carbon::parse($datetime);
        }

        $now = \Carbon\Carbon::now();

        \Log::info("Timestamp debug - Now: " . $now->toDateTimeString() . " | Comment time: " . $datetime->toDateTimeString());

        try {
            $diff = $now->timestamp - $datetime->timestamp;
        } catch (\Exception $e) {
            return 'unknown';
        }


        if ($diff < 0) {
            $diff = 0;
        }

        if ($diff < 60) {
            return 'just now';
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes . 'm';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . 'h';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . 'd';
        } elseif ($diff < 2592000) {
            $weeks = floor($diff / 604800);
            return $weeks . 'w';
        } else {
            $months = floor($diff / 2592000);
            return $months . 'mo';
        }
    }
    /**
     * Get initials from a name
     *
     * @param string $name
     * @return string
     */
    private function getInitials($name)
    {
        $nameParts = explode(' ', trim($name));
        $initials = '';

        if (count($nameParts) > 0) {
            $initials .= strtoupper(substr($nameParts[0], 0, 1));

            if (count($nameParts) > 1) {
                $initials .= strtoupper(substr(end($nameParts), 0, 1));
            }
        }

        return $initials ?: 'U';
    }
}
