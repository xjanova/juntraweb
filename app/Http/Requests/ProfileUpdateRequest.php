<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\NotReservedEmailDomain;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /** กติกาช่องชื่อ — แอพ (PATCH /v1/account/profile) ใช้ชุดเดียวกัน */
    public static function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => self::nameRules(),
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
                // เปลี่ยนเป็นอีเมลโดเมนสงวนไม่ได้ — อีเมลเดิมที่ระบบสร้างให้ยังบันทึกซ้ำได้
                new NotReservedEmailDomain($this->user()->email),
            ],
        ];
    }
}
