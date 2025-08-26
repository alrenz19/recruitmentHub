<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OptionResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'option_text' => $this->option_text,
            'is_correct' => $this->when(
                $request->route()->named('assessment.show') && 
                Auth::user()->can('view_answer_key', $this->question->assessment),
                $this->is_correct
            ),
        ];
    }
}