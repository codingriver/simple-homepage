<?php
/**
 * 后台公共底部 admin/shared/footer.php
 * 关闭 content、main-wrap 以及 body/html 标签
 */
?>
  </div><!-- .content -->
</div><!-- .main-wrap -->

<script>
// 移动端侧边栏切换（如有需要可扩展汉堡菜单）
if (window.innerWidth < 769) {
    // 点击主内容区关闭侧边栏
    document.querySelector('.main-wrap').addEventListener('click', function() {
        document.getElementById('sidebar').classList.remove('open');
    });
}
</script>
<script>window._csrf = <?= json_encode(csrf_token()) ?>;</script>
</body></html>
