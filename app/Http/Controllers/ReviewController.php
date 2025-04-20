<?php

namespace App\Http\Controllers;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReviewController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|max:1000',
        ]);

        $user = Auth::user();

        if ($user->review) {
            return response()->json([
                'status' => 'error',
                'message' => 'You have already submitted a review'
            ], 400);
        }

        $review = new Review([
            'user_id' => $user->id,
            'rating' => $validated['rating'],
            'comment' => $validated['comment']
        ]);

        $review->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Review submitted successfully',
            'data' => $review
        ]);
    }

    public function shouldShowReview()
    {
        $user = Auth::user();

        $hasCapsules = $user->capsules()->count() > 0;

        $hasReview = $user->review()->exists();

        return response()->json([
            'shouldShow' => $hasCapsules && !$hasReview
        ]);
    }
    public function getHighRatedReviews()
    {
        $reviews = Review::with('user')
            ->where('rating', '>=', 4)
            ->orderBy('created_at', 'desc')
            ->limit(9)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $reviews
        ]);
    }
}
