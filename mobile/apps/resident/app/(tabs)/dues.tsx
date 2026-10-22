import DuesScreen from '@/features/dues/screens/DuesScreen'
import { withLevel } from '@/features/access/LevelGate'

// Dues are the household's Members' (`access.may.dues`); another level is told so, and nothing is read.
export default withLevel('dues', 'Dues', DuesScreen)
