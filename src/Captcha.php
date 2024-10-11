<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2015 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

namespace think\captcha;

use Closure;
use Exception;
use think\Config;
use think\Response;
use think\Session;

class Captcha
{
    private $im    = null; // 验证码图片实例
    private $color = null; // 验证码字体颜色

    /**
     * @var Config|null
     */
    private $config = null;

    /**
     * @var Session|null
     */
    private $session = null;

    // 验证码字符集合
    protected $codeSet = '2345678abcdefhijkmnpqrstuvwxyzABCDEFGHJKLMNPQRTUVWXY';
    // 验证码过期时间（s）
    protected $expire = 1800;
    // 使用中文验证码
    protected $useZh = false;
    // 中文验证码字符串
    protected $zhSet = '';
    // 使用背景图片
    protected $useImgBg = false;
    // 是否画混淆曲线
    protected $useCurve = true;
    // 是否添加杂点
    protected $useNoise = true;
    // 验证码图片高度
    protected $imageH = 0;
    // 验证码图片宽度
    protected $imageW = 0;
    // 验证码位数
    protected $length = 5;
    // 验证码字体大小(px)
    protected $fontSize = 25;
    // 验证码字体，不设置随机获取
    protected $fontttf = '';
    // 背景颜色
    protected $bg = [243, 251, 254];
    //算术验证码
    protected $math = false;
    //从 0 到 127。0 表示完全不透明，127 表示完全透明。
    protected $alpha = 0;
    // 使用API模式生成
    protected $api = false;
    // 验证码干扰项及处理方法
    protected $interfere = [
        'useImgBg' => 'background',
        'useCurve' => 'writeCurve',
        'useNoise' => 'writeNoise',
    ];

    /**
     * 架构方法 设置参数
     * @access public
     * @param Config  $config
     * @param Session $session
     */
    public function __construct(Config $config, Session $session)
    {
        $this->config  = $config;
        $this->session = $session;
    }

    /**
     * 配置验证码
     * @param string|null $config
     */
    protected function configure(string $config = null): void
    {
        if (is_null($config)) {
            $config = $this->config->get('captcha', []);
        } else {
            $config = $this->config->get('captcha.' . $config, []);
        }

        foreach ($config as $key => $val) {
            if (property_exists($this, $key)) {
                $this->{$key} = $val;
            }
        }
    }

    /**
     * 创建验证码
     * @return array
     * @throws Exception
     */
    protected function generate(): array
    {
        $bag = '';

        if ($this->math) {
            $this->useZh  = false;
            $this->length = 5;

            $x   = random_int(10, 30);
            $y   = random_int(1, 9);
            $bag = "{$x} + {$y} = ";
            $key = $x + $y;
            $key .= '';
        } else {
            if ($this->useZh) {
                $characters = preg_split('/(?<!^)(?!$)/u', $this->zhSet);
            } else {
                $characters = str_split($this->codeSet);
            }

            for ($i = 0; $i < $this->length; $i++) {
                $bag .= $characters[random_int(0, count($characters) - 1)];
            }

            $key = mb_strtolower($bag, 'UTF-8');
        }

        $hash = password_hash($key, PASSWORD_BCRYPT, ['cost' => 10]);

        $this->session->set('captcha', [
            'key' => $hash,
        ]);

        return [
            'value' => $bag,
            'key'   => $hash,
        ];
    }

    /**
     * 验证验证码是否正确
     * @access public
     * @param string $code 用户验证码
     * @return bool 用户验证码是否正确
     */
    public function check(string $code): bool
    {
        if (!$this->session->has('captcha')) {
            return false;
        }

        $key  = $this->session->get('captcha.key');
        $code = mb_strtolower($code, 'UTF-8');
        $res  = password_verify($code, $key);

        if ($res) {
            $this->session->delete('captcha');
        }

        return $res;
    }

    /**
     * 输出验证码并把验证码的值保存的session中
     * @access public
     * @param null|string $config
     * @return Response|array
     */
    public function create(string $config = null)
    {
        $this->configure($config);

        $generator = $this->generate();

        $this->imageW || $this->imageW = $this->length * $this->fontSize * 1.5 + $this->length * $this->fontSize / 2;
        $this->imageH || $this->imageH = $this->fontSize * 2.5;

        $this->imageW = (int) $this->imageW;
        $this->imageH = (int) $this->imageH;

        // 建立一幅 $this->imageW x $this->imageH 的图像
        $this->im = imagecreate($this->imageW, $this->imageH);

        // 设置背景
        imagecolorallocatealpha($this->im, $this->bg[0], $this->bg[1], $this->bg[2], $this->alpha);

        // 验证码字体随机颜色
        $this->color = imagecolorallocate($this->im, mt_rand(1, 150), mt_rand(1, 150), mt_rand(1, 150));

        // 验证码使用随机字体
        $ttfPath = __DIR__ . '/../assets/' . ($this->useZh ? 'zhttfs' : 'ttfs') . '/';

        if (empty($this->fontttf)) {
            $dir  = dir($ttfPath);
            $ttfs = [];
            while (false !== ($file = $dir->read())) {
                if (substr($file, -4) === '.ttf' || substr($file, -4) === '.otf') {
                    $ttfs[] = $file;
                }
            }
            $dir->close();
            $this->fontttf = $ttfs[array_rand($ttfs)];
        }

        $fontttf = $ttfPath . $this->fontttf;

        // 添加干扰项
        foreach ($this->interfere as $type => $method) {
            if ($method instanceof Closure) {
                $method($this->im, $this->imageW, $this->imageH, $this->fontSize, $this->color);
            } elseif ($this->$type) {
                $this->$method();
            }
        }

        // 绘验证码
        $text = $this->useZh ? preg_split('/(?<!^)(?!$)/u', $generator['value']) : str_split($generator['value']); // 验证码

        foreach ($text as $index => $char) {
            $x     = $this->fontSize * ($index + 1) * ($this->math ? 1 : 1.5);
            $y     = $this->fontSize + mt_rand(10, 20);
            $angle = $this->math ? 0 : mt_rand(-40, 40);

            imagettftext($this->im, (int) $this->fontSize, $angle, (int) $x, (int) $y, $this->color, $fontttf, $char);
        }

        ob_start();
        // 输出图像
        imagepng($this->im);
        $content = ob_get_clean();
        imagedestroy($this->im);

        // API调用模式
        if ($this->api) {
            return [
                'code' => implode('', $text),
                'img'  => 'data:image/png;base64,' . base64_encode($content),
            ];
        }
        // 输出验证码图片
        return response($content, 200, ['Content-Length' => strlen($content)])->contentType('image/png');
    }

    /**
     * 画一条由两条连在一起构成的随机正弦函数曲线作干扰线(你可以改成更帅的曲线函数)
     *
     *      高中的数学公式咋都忘了涅，写出来
     *        正弦型函数解析式：y=Asin(ωx+φ)+b
     *      各常数值对函数图像的影响：
     *        A：决定峰值（即纵向拉伸压缩的倍数）
     *        b：表示波形在Y轴的位置关系或纵向移动距离（上加下减）
     *        φ：决定波形与X轴位置关系或横向移动距离（左加右减）
     *        ω：决定周期（最小正周期T=2π/∣ω∣）
     *
     */
    protected function writeCurve(): void
    {
        $px = $py = 0;

        // 曲线前部分
        $A = mt_rand(1, (int) ($this->imageH / 2)); // 振幅
        $b = mt_rand((int) (-$this->imageH / 4), (int) ($this->imageH / 4)); // Y轴方向偏移量
        $f = mt_rand((int) (-$this->imageH / 4), (int) ($this->imageH / 4)); // X轴方向偏移量
        $T = mt_rand($this->imageH, $this->imageW * 2); // 周期
        $w = (2 * M_PI) / $T;

        $px1 = 0; // 曲线横坐标起始位置
        $px2 = mt_rand((int) ($this->imageW / 2), (int) ($this->imageW * 0.8)); // 曲线横坐标结束位置

        for ($px = $px1; $px <= $px2; $px = $px + 1) {
            if (0 != $w) {
                $py = $A * sin($w * $px + $f) + $b + $this->imageH / 2; // y = Asin(ωx+φ) + b
                $i  = (int) ($this->fontSize / 5);
                while ($i > 0) {
                    imagesetpixel($this->im, (int) ($px + $i), (int) ($py + $i), $this->color); // 这里(while)循环画像素点比imagettftext和imagestring用字体大小一次画出（不用这while循环）性能要好很多
                    $i--;
                }
            }
        }

        // 曲线后部分
        $A   = mt_rand(1, (int) ($this->imageH / 2)); // 振幅
        $f   = mt_rand((int) (-$this->imageH / 4), (int) ($this->imageH / 4)); // X轴方向偏移量
        $T   = mt_rand($this->imageH, $this->imageW * 2); // 周期
        $w   = (2 * M_PI) / $T;
        $b   = $py - $A * sin($w * $px + $f) - $this->imageH / 2;
        $px1 = $px2;
        $px2 = $this->imageW;

        for ($px = $px1; $px <= $px2; $px = $px + 1) {
            if (0 != $w) {
                $py = $A * sin($w * $px + $f) + $b + $this->imageH / 2; // y = Asin(ωx+φ) + b
                $i  = (int) ($this->fontSize / 5);
                while ($i > 0) {
                    imagesetpixel($this->im, (int) ($px + $i), (int) ($py + $i), $this->color);
                    $i--;
                }
            }
        }
    }

    /**
     * 画杂点
     * 往图片上写不同颜色的字母或数字
     */
    protected function writeNoise(): void
    {
        $codeSet = '2345678abcdefhijkmnpqrstuvwxyz';
        for ($i = 0; $i < 10; $i++) {
            //杂点颜色
            $noiseColor = imagecolorallocate($this->im, mt_rand(150, 225), mt_rand(150, 225), mt_rand(150, 225));
            for ($j = 0; $j < 5; $j++) {
                // 绘杂点
                imagestring($this->im, 5, mt_rand(-10, $this->imageW), mt_rand(-10, $this->imageH), $codeSet[mt_rand(0, 29)], $noiseColor);
            }
        }
    }

    /**
     * 绘制背景图片
     * 注：如果验证码输出图片比较大，将占用比较多的系统资源
     */
    protected function background(): void
    {
        $path = __DIR__ . '/../assets/bgs/';
        $dir  = dir($path);

        $bgs = [];
        while (false !== ($file = $dir->read())) {
            if ('.' != $file[0] && substr($file, -4) == '.jpg') {
                $bgs[] = $path . $file;
            }
        }
        $dir->close();

        $gb = $bgs[array_rand($bgs)];

        [$width, $height] = @getimagesize($gb);
        // Resample
        $bgImage = @imagecreatefromjpeg($gb);
        @imagecopyresampled($this->im, $bgImage, 0, 0, 0, 0, $this->imageW, $this->imageH, $width, $height);
        @imagedestroy($bgImage);
    }
}
