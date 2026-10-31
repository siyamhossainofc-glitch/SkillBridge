<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Skill;

class SkillController extends Controller
{
    public function index()
    {
        $skills = Skill::with('category:id,name,slug')->orderBy('name')->get();

        return response()->json([
            'skills' => $skills,
        ]);
    }
    public function mySkills(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'skills' => $user->skills()->with('category:id,name,slug')->orderBy('name')->get(),
        ]);
    }

    public function attachSkill(Request $request)
    {
        $validated = $request->validate([
            'skill_id' => 'required|integer|exists:skills,id',
        ]);

        return $this->addSkill($request, $validated['skill_id']);
    }

    public function addSkill(Request $request, $skillId)
    {
        $user = $request->user();

        $skill = Skill::find($skillId);

        if (!$skill) {
            return response()->json([
                'message' => 'Skill not found'
            ], 404);
        }

        $user->skills()->syncWithoutDetaching([$skillId]);

        return response()->json([
            'message' => 'Skill added successfully',
            'skills' => $user->load('skills.category:id,name,slug')->skills,
        ]);
    }

    public function removeSkill(Request $request, $skillId)
    {
        $user = $request->user();

        $skill = Skill::find($skillId);

        if (!$skill) {
            return response()->json([
                'message' => 'Skill not found'
            ], 404);
        }

        $user->skills()->detach($skillId);

        return response()->json([
            'message' => 'Skill removed successfully',
            'skills' => $user->load('skills.category:id,name,slug')->skills,
        ]);
    }
}
