<?php

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => 'required|string|min:3|max:255',
            'email' => 'required|email|max:255',
            'subject' => 'nullable|string|max:255',
            'message' => 'required|string|min:10|max:5000',
        ];
    }

    public function messages()
    {
        return [
            'name.min' => 'Please enter your full name.',
            'message.min' => 'Please tell us a little more so we can help.',
        ];
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => trim((string) $this->input('email')),
            'subject' => $this->filled('subject') ? trim((string) $this->input('subject')) : null,
            'message' => trim((string) $this->input('message')),
        ]);
    }
}
