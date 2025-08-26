<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class QuestionResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'question_text' => $this->question_text,
            'question_type' => $this->question_type,
            'image_path' => $this->image_path ? asset("storage/{$this->image_path}") : null,
            'options' => OptionResource::collection($this->whenLoaded('options')),
        ];
    }
}