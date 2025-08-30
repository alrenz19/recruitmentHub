<?php
// app/Http/Resources/AssessmentResource.php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'time_allocated' => $this->time_allocated,
            'time_unit' => $this->time_unit,
            'created_at' => $this->created_at,
            'created_by_user' => $this->whenLoaded('createdByUser', function () {
                return [
                    'id'        => $this->createdByUser->id,
                    'email'     => $this->createdByUser->user_email,
                    'full_name' => $this->createdByUser->full_name, 
                ];
            }),
            'questions' => QuestionResource::collection($this->whenLoaded('questions')),
        ];
    }
}

class QuestionResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'question_text' => $this->question_text,
            'question_type' => $this->question_type,
            'image_path' => $this->image_path,
            'options' => OptionResource::collection($this->whenLoaded('options')),
        ];
    }
}

class OptionResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'option_text' => $this->option_text,
            'is_correct' => $this->is_correct,
        ];
    }
}