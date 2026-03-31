# index.html 功能流程分析

## 1. 文件定位

这个页面是一个单文件前端应用，目标是让用户完成一套“祝福牌”制作流程：

1. 选择图片。
2. 在 3:4 预览框内对图片进行拖动和缩放。
3. 输入祝福语并加载指定字体。
4. 调整文字大小、颜色、横竖排和位置。
5. 实时预览三色墨水屏效果。
6. 可选录制一段语音。
7. 将图片和音频一起上传到服务端。

页面全部逻辑都写在 index.html 内部脚本中，外部仅依赖：

- tricolor.js：三色墨水屏转换器。
- 远程字体接口 https://www.twobaozi.com/qfp/font/dfont.php。
- 本地上传接口 ./dl/up.php。

## 2. 页面结构概览

页面可以分成 6 个功能区：

### 2.1 初始选图层

- 元素：initialPicker、btnPickInitial、fileInitial。
- 作用：页面初始只展示一个居中的“选择图片”按钮。
- 用户选图后，初始层隐藏，主内容区域显示。

### 2.2 主编辑区

- 元素：mainContent。
- 包含图片预览框、文字输入区、录音区、发送区、三色下载区。
- 在没有选图前整体隐藏。

### 2.3 图片预览框

- 元素：frame、img、placeholder、textLayer、tricolorOverlay。
- 作用：
  - img 展示原图。
  - textLayer 叠加显示祝福文字。
  - tricolorOverlay 显示三色渲染结果。
  - placeholder 在未选图时提示操作。

### 2.4 文字编辑区

- 元素：captionInput、fontSelect、btnLoadFont。
- 扩展控制：
  - 字号按钮：btnFontSizeDown、btnFontSizeUp。
  - 排版切换：btnTextDirection。
  - 颜色按钮：btnColorBlack、btnColorRed、btnColorWhite。
- 特点：点击“添加祝福语”成功后，输入框与字体选择会被隐藏，页面进入后续编辑阶段。

### 2.5 录音区

- 元素：btnRecord、recordProgress、recordProgressBar、recordTimer、recordedAudio。
- 作用：按住录音，松开停止，最长 10 秒。

### 2.6 发送与结果反馈

- 元素：btnSendBlessing、sendSuccessOverlay。
- 作用：将最终图像和可选音频上传；上传成功后切换到全屏成功提示。

## 3. 初始化流程

### 3.1 DOMContentLoaded 时请求麦克风权限

页面加载后会立即调用 navigator.mediaDevices.getUserMedia({ audio: true })。

这一步的目的不是立即录音，而是提前触发浏览器权限请求，减少真正按下录音按钮时的等待。

流程如下：

1. 请求音频输入权限。
2. 成功后立即关闭 stream 的所有 track。
3. 失败则只输出 console.warn，不阻断页面继续使用。

### 3.2 读取 URL 参数

脚本会读取 window.location.search，并去掉前导 ? 后进行 decodeURIComponent。

该值主要有两个用途：

1. 在初始选图层和工具栏里显示出来。
2. 发送祝福时作为上传接口的查询参数直接带给 ./dl/up.php。

代码对这个参数的业务格式要求很严格，后续上传时会校验必须满足：

- 4 位年月标识。
- 9 位字母数字串。
- 8 位字母数字串。

合起来总格式为：YYYY + 9 位设备标识 + 8 位 key。

## 4. 状态模型

页面主要依赖 4 组状态。

### 4.1 state：图片与手势状态

字段职责：

- hasImage：是否已选图。
- objectUrl：当前图片的 blob URL。
- scale、tx、ty：当前图片缩放与平移值。
- minScale、maxScale：缩放边界。
- panStart、pinchStart：手势开始时的快照。
- imgW、imgH：原图尺寸。

这组状态负责维持“图片必须完整覆盖 3:4 框，不能露底”的约束。

### 4.2 fontState：文字字号状态

- 默认字号 34。
- 最小 12，最大 48。
- 每次步进 2。

### 4.3 colorState：文字颜色状态

- 默认颜色是红色 #d1242f。

### 4.4 textState：文字层位置状态

- x、y：文字层左上角位置。
- dragStart：文字拖动开始时的基线。
- pointerId：当前控制文字拖动的指针。
- margin：文字与边缘的最小留白。

### 4.5 其他关键标志位

- textLocked：文字加载完成后设为 true，图片将不再允许拖动和缩放。
- lastTricolorResult：最近一次三色转换后的 canvas，用于下载与发送。
- lastRecordedBlob：最近一次录到的音频 Blob，用于上传。

## 5. 图片处理主流程

## 5.1 入口：setImageFromFile(file)

当用户通过初始按钮或工具栏按钮选择图片后，最终都会进入 setImageFromFile(file)。

这个函数完成以下动作：

1. 如果旧 objectUrl 存在，先释放 URL.revokeObjectURL。
2. 用新文件创建 blob URL，赋值给 img.src。
3. 隐藏 placeholder。
4. 启用“重置”按钮。
5. 等待 img.onload。

在 img.onload 之后，才进入真正的图片初始化：

1. 记录 naturalWidth / naturalHeight。
2. 清空手势快照。
3. 调用 computeCoverTransform() 计算初始铺满方案。
4. 调用 applyTransform() 把变换写回 DOM。
5. 连续两个 requestAnimationFrame 后调用 autoConvertTricolor()，生成首次三色预览。

### 5.2 computeCoverTransform()

这个函数是图片铺满逻辑的核心。

它会：

1. 读取 frame 的显示尺寸。
2. 用 max(frameWidth / imgWidth, frameHeight / imgHeight) 计算 cover 缩放值。
3. 将该值同时设置为初始 scale 与 minScale。
4. 将 maxScale 设为 minScale 的 6 倍。
5. 计算居中显示所需的 tx、ty。
6. 调用 clampTranslate() 防止数值误差导致露底。

因此，这个页面不是“contain”，而是“cover”裁剪模型。

### 5.3 clampTranslate()

该函数用于强制限制平移范围：

- 横向范围是 [frameWidth - displayWidth, 0]。
- 纵向范围是 [frameHeight - displayHeight, 0]。

如果缩放后显示尺寸比容器还小，就强制居中，避免出现空白边。

## 6. 手势交互流程

页面使用 Pointer Events 统一处理鼠标、触摸和多指操作。

### 6.1 单指拖动图片

流程：

1. 在 frame 上 pointerdown 时，把该指针位置记录进 pointers。
2. 如果当前只有一个指针，则调用 startPan(pointerId)。
3. pointermove 时调用 updateFromPointers()。
4. 通过当前位置减去起始位置，叠加到 tx、ty 上。
5. 最后经过 clampTranslate() 修正边界。

### 6.2 双指缩放图片

流程：

1. 当 pointers 数量达到 2 时进入 startPinch()。
2. startPinch() 会记录：
   - 两指初始距离 dist0。
   - 初始 scale。
   - 双指中点所对应的“内容坐标” content0。
3. pointermove 时重新计算当前双指距离 dist。
4. 用 dist / dist0 推出新的 scale。
5. 用中点回推 tx、ty，保持缩放围绕双指中点发生。
6. 再经过 clampTranslate() 确保不会露底。

### 6.3 手势过程中的三色预览切换

为了避免拖拽过程中频繁做像素级转换：

1. 开始图片手势时隐藏 tricolorOverlay。
2. 同时把原图 img 恢复可见。
3. 当所有指针结束后，延迟 50ms 再调用 autoConvertTricolor() 重新渲染三色图。

这说明三色预览不是实时逐帧转换，而是“交互时显示原图，交互结束再刷新三色结果”。

### 6.4 文字锁定后的行为变化

frame 的 pointerdown 中有一条关键判断：如果 textLocked 为 true，则直接 return。

这意味着：

- 在祝福语生成完成之前，用户只能调整图片。
- 在祝福语生成完成之后，图片被冻结，用户只能拖动文字。

这是当前页面的重要交互分段设计。

### 6.5 resize 对图片视图的影响

移动端在输入祝福语后，软键盘收起通常会触发一次 resize。

这一类 resize 如果直接重新执行初始 cover 计算，就会把用户之前调整好的图片位置和缩放状态重置掉。

当前实现已经改成：

1. resize 时保留原先视图中心对应的图片内容坐标。
2. 按新的最小缩放值重新换算相对缩放比例。
3. 在新的 frame 尺寸下回推 tx、ty。
4. 最后只做边界裁剪，不再回到初始载入状态。

因此，用户在第二步对图片做过的移动和缩放，在进入后续文字编辑阶段后应当可以继续保持。

## 7. 文字处理流程

## 7.1 showCaption()

这个函数负责真正把输入框内容展示到 textLayer。

逻辑如下：

1. 读取 captionInput 内容并保留换行。
2. 空文本则隐藏 textLayer。
3. 非空则：
   - 显示 textLayer。
   - 写入 textContent。
   - 应用当前字体、字号、颜色。
   - 根据颜色设置不同阴影，以保证浅色或深色背景上的可读性。
4. 如果文字位置还未初始化，则默认放在靠左下角的位置。
5. 调用 clampTextPosition() 把文字约束在 frame 内。
6. 调用 scheduleTricolorRender() 触发三色重绘。

### 7.2 远程字体加载：loadFontSubset()

该函数是“添加祝福语”按钮的核心。

分两种分支：

#### 分支 A：选择默认字体

如果 fontSelect 的值是 default：

1. 直接把 currentFontFamily 设为 sans-serif。
2. 调用 showCaption()。
3. 将 textLocked 设为 true。
4. 隐藏输入框、字体选择框和按钮本身。
5. 显示字号、排版、颜色、录音、发送这些后续功能。

#### 分支 B：选择远程字体

如果选择的是指定字体：

1. 先校验输入文字是否为空。
2. 禁用按钮并显示 loadingOverlay。
3. 调用远程接口：
   - 参数 font 是字体名。
   - 参数 text 是当前祝福语。
4. 读取返回的 arrayBuffer。
5. 根据 Content-Type 推断格式是 ttf、woff 或 woff2。
6. 创建 FontFace 并动态注册到 document.fonts。
7. 设置 currentFontFamily 为动态字体名。
8. 调用 showCaption()。
9. 锁定图片，并展示后续编辑区。
10. 成功或失败都恢复按钮状态并关闭 loadingOverlay。

这一段的目标是按输入文本生成字体子集，减少前端一次性加载整套中文字体的成本。

### 7.3 文字位置拖拽

文字拖动单独绑定在 textLayer 上，不与图片手势共用。

流程：

1. pointerdown 时记录指针和拖动起点。
2. pointermove 时根据位移更新 textState.x / y。
3. 每次移动后都调用 clampTextPosition()，防止文字拖出预览框。
4. pointerup / pointercancel 时结束拖动，并补一次三色渲染。

### 7.4 文字方向切换

toggleTextDirection() 会在横排和竖排间切换：

- 竖排：writing-mode = vertical-rl，text-orientation = upright。
- 横排：writing-mode = horizontal-tb，text-orientation = mixed。

切换后立即重新约束位置并刷新三色预览。

### 7.5 文字颜色与阴影策略

颜色按钮直接修改 colorState.color 和 textLayer.style.color。

同时根据颜色变化，阴影策略也会变化：

- 红色或黑色文字：使用偏亮阴影。
- 白色文字：使用偏暗阴影。

代码意图是提升不同背景上的对比度，但这里的阴影命名和视觉结果不完全一致，属于经验型调节。

## 8. 三色墨水屏预览流程

### 8.1 captureFrame(scale)

该函数先把当前 frame 的视觉结果渲染成一个离屏 canvas。

渲染内容包括：

1. 白色背景。
2. 当前图片在 tx / ty / scale 下的可视区域。
3. 当前文字层内容。

如果使用原图模式 scale = 0，会反推当前 frame 在原图中的可见区域尺寸，输出更接近原始采样比例的截图。

### 8.2 convertToTricolorCanvas(sourceCanvas)

该函数是 tricolor.js 的封装层。

它会从隐藏控件中读取以下参数并传给 TriColorConverter：

- 黑白阈值。
- gamma。
- 自动对比度比例。
- 红色检测饱和度、明度和红色增量阈值。
- 红色抖动开关。
- 红色增强参数。

虽然参数区当前被隐藏，但逻辑已经具备调参能力。

### 8.3 autoConvertTricolor()

这是三色预览刷新主函数。

流程：

1. 如果没有图片则直接返回。
2. 从 outputScaleSelect 读取输出倍数，默认 2x。
3. 用 captureFrame(scale) 获取合成后的画面。
4. 把尺寸信息写到 tricolorInfo。
5. 调用 convertToTricolorCanvas() 生成三色结果。
6. 把结果绘制到 tricolorOverlay canvas。
7. 显示 tricolorOverlay，隐藏原始 img。
8. 启用“下载三色图”按钮。

### 8.4 scheduleTricolorRender()

该函数通过 requestAnimationFrame 对重绘做最基础的合并，避免同一帧内重复触发 autoConvertTricolor()。

适用于文字移动、字号变化、颜色变化等高频操作。

## 9. 录音流程

录音模块基于 MediaRecorder。

### 9.1 startRecording()

点击录音按钮时会：

1. 请求麦克风权限。
2. 创建 MediaRecorder。
3. 设置 ondataavailable，持续收集音频块。
4. 设置 onstop：
   - 停止所有音轨。
   - 把 recordedChunks 合成 Blob。
   - 保存到 lastRecordedBlob。
   - 生成音频 URL 并赋值给 recordedAudio。
   - 音频加载完成后自动播放。
5. 调用 mediaRecorder.start(100)，每 100ms 收集一次数据。
6. 记录录音开始时间。
7. 切换按钮样式与进度条显示。
8. 启动 10 秒自动停止定时器。

### 9.2 updateRecordProgress()

基于 requestAnimationFrame 刷新倒计时和剩余进度百分比。

### 9.3 stopRecording()

该函数会：

1. 清理超时定时器。
2. 如果仍在录音则停止 MediaRecorder。
3. 恢复按钮样式和进度条状态。
4. 重置录音倒计时显示。

### 9.4 交互方式

采用“按住录、松手停”的模式：

- pointerdown 开始录音。
- pointerup 停止录音。
- pointerleave / pointercancel 时如果还在录，也会立即停止。

## 10. 发送祝福流程

### 10.1 buildImageBlob(scale)

上传前不会直接发送屏幕上的 overlay，而是重新构造一张图片 Blob。

步骤是：

1. 调用 captureFrame(scale) 得到高分辨率源图。
2. 创建一个固定尺寸的 scaledCanvas。
3. 把 sourceCanvas 画入 scaledCanvas。
4. 在缩放后的目标图上重新执行三色抖动转换。
5. 将结果导出为 PNG Blob。

这里代码注释写的是“先缩放到 400x300”，但实际 canvas 尺寸被设置成 270x400，drawImage 参数却是 400x300。这说明当前实现存在注释与代码不一致的问题，且可能带来拉伸或截断风险。

### 10.2 uploadBlessing()

这是上传主函数。

流程：

1. 读取当前 URL 查询串。
2. 用正则校验参数格式，不符合则 alert 并返回 false。
3. 从 outputScaleSelect 读取缩放参数。
4. 调用 buildImageBlob() 生成图片。
5. 创建 FormData。
6. 固定追加 image 字段。
7. 如果 lastRecordedBlob 存在，则追加 audio 字段。
8. POST 到 ./dl/up.php?原始查询参数。
9. 读取服务端文本响应。
10. 如果响应中带有 script 标签，则交给 runServerLogs() 用隐藏 iframe 执行，目的是把服务端附带的 console.log 打到前端控制台。
11. 如果 HTTP 状态不是成功，则通过 extractPlainText() 提取纯文本错误并抛出异常。
12. 成功则返回 true。

### 10.3 点击发送按钮后的页面行为

btnSendBlessing 点击后：

1. 如果尚未选图，直接提示。
2. 禁用按钮并把文案改成“发送中...”。
3. 调用 uploadBlessing()。
4. 上传成功后隐藏 mainContent，展示 sendSuccessOverlay。
5. 上传失败则 alert 错误信息。
6. finally 中恢复按钮状态和文字。

注意：成功提示层中的“将在 00:00 分更新”是静态文案，当前代码没有真实倒计时或服务端返回时间绑定逻辑。

## 11. 与服务端 up.php 的协作关系

前端上传时的行为，可以从接口实现反推出更完整的业务意图：

1. URL 参数被拆成 monthYear、mac62、key 三部分。
2. 服务端会定位到对应目录和 config.json。
3. 校验 key 合法后把 state 自增。
4. 保存图片为 key + state + .png。
5. 调用 minpng.py 压缩图片。
6. 如果上传了音频，则保存为 key + state + .wav，并调用 enc_au_pic.py 做后处理。
7. 最后写回 config.json 中的 state。

因此，这个前端页面的真正业务结果并不是“仅上传一张图”，而是“为某个设备/某个祝福牌生成一版新的图片与可选音频资产”。

## 12. 关键设计特点

### 12.1 单文件应用

所有前端逻辑集中在一个 HTML 中，开发和部署简单，但维护成本会随着功能增长快速上升。

### 12.2 分阶段编辑

页面把操作过程切成两个阶段：

1. 先调图片。
2. 再锁图、调文字、录音、发送。

这个设计降低了交互冲突，但也限制了“先加字再微调底图”的灵活性。

### 12.3 三色预览和最终上传保持同一转换逻辑

预览与上传都复用 convertToTricolorCanvas()，这保证了用户看到的效果与最终上传结果方向一致。

### 12.4 远程字体子集化

这是页面里最明显的性能优化点之一。相比预加载完整中文字体，按文本请求子集更适合移动端使用。

## 13. 风险点与注意事项

### 13.1 buildImageBlob 的尺寸逻辑存在不一致

- 注释写 400x300。
- canvas 实际是 270x400。
- drawImage 却传入 400x300。

这一段需要进一步确认目标设备的真实分辨率，否则最终上传图可能与预览比例不一致。

### 13.2 textLocked 会彻底禁止图片再编辑

用户一旦点击“添加祝福语”成功，就无法继续调整图片，只能调整文字。这是明确设计，但也可能成为使用上的限制。

### 13.3 录音 MIME 类型和文件扩展名不一定一致

前端录音 Blob 使用的是 MediaRecorder 产出的 mimeType，常见可能是 audio/webm；但上传时文件名固定写成 record.wav。服务端目前直接按 wav 命名保存，格式是否完全匹配依赖浏览器实现和后续脚本兼容性。

### 13.4 服务端日志通过 iframe 执行脚本

runServerLogs() 会把响应中的 script 插入隐藏 iframe 执行。这有助于把 PHP 附带日志输送到浏览器控制台，但也意味着前后端在这个点存在脚本执行耦合。

### 13.5 成功页没有闭环返回机制

上传成功后页面直接切到成功遮罩，没有“重新编辑”或“继续发送”的入口。

## 14. 一句话总结

这个文件实现的是一个面向墨水屏祝福牌场景的前端制作器：先选图并裁剪，再叠加文字与录音，最后把三色处理后的结果连同音频上传到指定设备目录；核心难点集中在图片 cover 手势、文字层编辑、三色抖动转换和上传前再采样这四段流程上。