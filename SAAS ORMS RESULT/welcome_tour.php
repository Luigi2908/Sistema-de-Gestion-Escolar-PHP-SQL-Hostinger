<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * First-run Welcome Tour — include just before </body>.
 * Include-only: the host page already loaded config.php and started the session.
 */

// logged out, or hit standalone -> emit nothing at all
if (empty($_SESSION['user_id']) || !function_exists('can')) return;

$tour_uid  = (int) $_SESSION['user_id'];
$tour_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';

// gate = registry keys, step survives if ANY of them is viewable (empty gate = always)
switch ($tour_role) {
    case 'Teacher':
        $tour_steps = [
            ['icon' => 'fa-chart-line', 'gate' => ['dashboard'], 'title' => 'Dashboard',
             'text' => 'Your classes, how much marks entry is still pending, and the results already published — all on one screen.'],
            ['icon' => 'fa-list-check', 'gate' => ['marks_entry'], 'title' => 'My Assignments',
             'text' => 'Every class, section and subject you have been given shows up as a card with its own entry progress.'],
            ['icon' => 'fa-pen-to-square', 'gate' => ['marks_entry'], 'title' => 'Marks Entry',
             'text' => 'Open the grid and type a whole class without touching the mouse — Enter jumps you to the next student.'],
            ['icon' => 'fa-award', 'gate' => ['results'], 'title' => 'Results',
             'text' => 'Once a term is published you can view the final results and the tabulation sheet for your sections.'],
        ];
        break;

    case 'Student':
        $tour_steps = [
            ['icon' => 'fa-chart-line', 'gate' => ['dashboard'], 'title' => 'Dashboard',
             'text' => 'Your latest result at a glance — percentage, grade and position, plus how you are doing across terms.'],
            ['icon' => 'fa-file-lines', 'gate' => ['my_results'], 'title' => 'My Results',
             'text' => 'Every term that has been published is listed here, newest first, so you can look back at any exam.'],
            ['icon' => 'fa-print', 'gate' => ['my_results'], 'title' => 'Result Card',
             'text' => 'Open any result to see the full mark sheet, then print it or save it as a PDF.'],
        ];
        break;

    case 'Principal':
        $tour_steps = [
            ['icon' => 'fa-chart-line', 'gate' => ['dashboard'], 'title' => 'Dashboard',
             'text' => 'The whole school on one screen — pass rates, how each class compares, and which sections are still waiting on you.'],
            ['icon' => 'fa-graduation-cap', 'gate' => ['classes', 'subjects', 'teachers'], 'title' => 'Academics',
             'text' => 'Classes, sections, subjects and staff. You can add and correct anything here, though only the admin can delete.'],
            ['icon' => 'fa-list-check', 'gate' => ['marks_entry'], 'title' => 'Entry Progress',
             'text' => 'Watch how far each teacher has got with their marks. You can see every grid, but entering marks stays with the teacher.'],
            ['icon' => 'fa-stamp', 'gate' => ['results'], 'title' => 'Approve & Publish',
             'text' => 'A section comes to you once its marks are complete. Approve it and it can go live; reject it with a note and it goes back.'],
            ['icon' => 'fa-sliders', 'gate' => ['result_settings'], 'title' => 'Result Settings',
             'text' => 'The grading scheme, card design, and your name and signature that print on every result card.'],
        ];
        break;

    default: // Admin + any custom role — perms decide what survives
        $tour_steps = [
            ['icon' => 'fa-chart-line', 'gate' => ['dashboard'], 'title' => 'Dashboard',
             'text' => 'Students, teachers, entry progress and pass rates are all summed up here the moment you log in.'],
            ['icon' => 'fa-graduation-cap', 'gate' => ['classes', 'subjects', 'teachers'], 'title' => 'Academics',
             'text' => 'Set up classes and their sections, add subjects, and register teachers with the subjects they teach.'],
            ['icon' => 'fa-user-graduate', 'gate' => ['students'], 'title' => 'Students',
             'text' => 'Register students one at a time or import a whole class from CSV, then promote them all at the end of the year.'],
            ['icon' => 'fa-award', 'gate' => ['marks_entry', 'results'], 'title' => 'Marks & Results',
             'text' => 'Enter marks for a full class in one grid, publish the term when it is complete, and print result cards for everyone.'],
            ['icon' => 'fa-sliders', 'gate' => ['result_settings'], 'title' => 'Result Settings',
             'text' => 'School name, logo, exam terms and the grading scheme — everything printed on the result card is yours to change.'],
        ];
}

// drop anything this role cannot actually open
$tour_steps = array_values(array_filter($tour_steps, function ($s) {
    if (empty($s['gate'])) return true;
    foreach ($s['gate'] as $k) if (can($k, 'v')) return true;
    return false;
}));

if (!$tour_steps) return; // nothing left worth showing
$tour_total = count($tour_steps);
$tour_key   = 'orms_tour_' . $tour_uid;
?>
<!-- First-run Welcome Tour — shown once per user (localStorage) -->
<div class="orms-tour-overlay no-print" id="ormsTour" data-tour-key="<?php echo htmlspecialchars($tour_key, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="orms-tour-card" role="dialog" aria-modal="true" aria-label="Welcome tour">
        <button type="button" class="orms-tour-close" data-tour="skip" aria-label="Skip tour"><i class="fas fa-xmark"></i></button>

        <div class="orms-tour-body">
            <?php foreach ($tour_steps as $i => $s): ?>
            <div class="orms-tour-step<?php echo $i === 0 ? ' orms-tour-on' : ''; ?>">
                <div class="orms-tour-ico"><i class="fas <?php echo htmlspecialchars($s['icon']); ?>"></i></div>
                <h3 class="orms-tour-title"><?php echo htmlspecialchars($s['title']); ?></h3>
                <p class="orms-tour-text"><?php echo htmlspecialchars($s['text']); ?></p>
            </div>
            <?php endforeach; ?>

            <div class="orms-tour-dots">
                <?php foreach ($tour_steps as $i => $s): ?>
                <button type="button" class="orms-tour-dot<?php echo $i === 0 ? ' orms-tour-on' : ''; ?>" data-tour-go="<?php echo (int) $i; ?>" aria-label="Step <?php echo (int) ($i + 1); ?>: <?php echo htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8'); ?>"></button>
                <?php endforeach; ?>
            </div>
            <div class="orms-tour-count" id="ormsTourCount">Step 1 of <?php echo (int) $tour_total; ?></div>
        </div>

        <div class="orms-tour-actions">
            <button type="button" class="btn btn-secondary orms-tour-btn" data-tour="skip"><i class="fas fa-xmark"></i> Skip</button>
            <div class="orms-tour-nav">
                <button type="button" class="btn btn-secondary orms-tour-btn" data-tour="back" disabled><i class="fas fa-arrow-left"></i> Back</button>
                <button type="button" class="btn btn-primary orms-tour-btn" data-tour="next">Next <i class="fas fa-arrow-right"></i></button>
                <button type="button" class="btn btn-success orms-tour-btn orms-tour-hide" data-tour="done"><i class="fas fa-check"></i> Get Started</button>
            </div>
        </div>
    </div>
</div>

<script>
// welcome tour — self-contained, no globals leaked
(function () {
    if (typeof window.jQuery === 'undefined') return;
    var $ = window.jQuery;

    $(function () {
        var $t = $('#ormsTour');
        if (!$t.length) return;

        var key   = $t.attr('data-tour-key'),
            total = $t.find('.orms-tour-step').length,
            i     = 0;

        // seen already -> drop it before anything paints
        try { if (window.localStorage && localStorage.getItem(key)) { $t.remove(); return; } } catch (e) {}
        if (!total) { $t.remove(); return; }

        function show(n) {
            i = Math.max(0, Math.min(total - 1, n));
            $t.find('.orms-tour-step').removeClass('orms-tour-on').eq(i).addClass('orms-tour-on');
            $t.find('.orms-tour-dot').removeClass('orms-tour-on').eq(i).addClass('orms-tour-on');
            $t.find('#ormsTourCount').text('Step ' + (i + 1) + ' of ' + total);
            $t.find('[data-tour="back"]').prop('disabled', i === 0);
            $t.find('[data-tour="next"]').toggleClass('orms-tour-hide', i >= total - 1);
            $t.find('[data-tour="done"]').toggleClass('orms-tour-hide', i < total - 1);
        }

        function close() {
            try { localStorage.setItem(key, '1'); } catch (e) {} // never nag again
            $t.removeClass('orms-tour-open');
            $('body').removeClass('orms-tour-lock');
            $(document).off('keydown.ormsTour');
            setTimeout(function () { $t.remove(); }, 260); // let the fade finish
        }

        $t.on('click', '[data-tour]', function () {
            var a = $(this).attr('data-tour');
            if (a === 'next') show(i + 1);
            else if (a === 'back') show(i - 1);
            else close(); // skip + done
        }).on('click', '[data-tour-go]', function () {
            show(parseInt($(this).attr('data-tour-go'), 10) || 0);
        }).on('click', function (e) {
            if (e.target === this) close(); // backdrop
        });

        $(document).on('keydown.ormsTour', function (e) {
            if (e.key === 'Escape') close();
            else if (e.key === 'ArrowRight') show(i + 1);
            else if (e.key === 'ArrowLeft') show(i - 1);
        });

        if (total < 2) $t.find('.orms-tour-dots, .orms-tour-count').addClass('orms-tour-hide'); // single step, no dots

        show(0);
        $('body').addClass('orms-tour-lock');
        $t.addClass('orms-tour-open');
        setTimeout(function () { $t.find('[data-tour="next"], [data-tour="done"]').not('.orms-tour-hide').trigger('focus'); }, 80);
    });
})();
</script>
