<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

// login 入参校验（manifest LoginRequest：username/password/clientId 全 required）。
// rails SessionsController#login_params 镜像：缺键 → ArgumentError "#{key}: is required"
// → 基座映射 400 BAD_REQUEST——不走 Laravel 默认 422 errors bag（家族错误契约是 {code,message}）。
final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'username' => ['required'],
            'password' => ['required'],
            'clientId' => ['required'],
        ];
    }

    // 覆写校验失败：抛 ArgumentError 语义（基座 400），首键对齐 rails "#{key}: is required"
    protected function failedValidation(Validator $validator): never
    {
        $keys = $validator->errors()->keys();
        $key = $keys[0] ?? 'body';
        throw new InvalidArgumentException("{$key}: is required");
    }
}
