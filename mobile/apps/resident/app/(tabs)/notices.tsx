import NoticesScreen from '@/features/notices/screens/NoticesScreen'
import { withLevel } from '@/features/access/LevelGate'

// Notices go to Members and Dependents (`access.may.notices`); a Helper is told so, and nothing is read.
export default withLevel('notices', 'Notices', NoticesScreen)
